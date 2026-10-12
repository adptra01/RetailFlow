<?php

namespace App\Actions\Payments;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Enums\PaymentVerification;
use App\Enums\PaymentWebhookOutcome;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Models\PaymentCharge;
use App\Models\PaymentWebhookEvent;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentGateway;
use App\Payments\PaymentWebhookResult;
use App\Payments\ProviderWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Mode B — Gateway: "Callback/webhook handler", "Verifikasi tanda tangan",
 * "Idempotensi callback" (plan §14), dengan pengaman replay (§15).
 *
 * Urutan yang dijaga:
 *   1. penyedia harus terdaftar; adaptornya yang memverifikasi tanda tangan,
 *      jadi peristiwa palsu mati sebelum menyentuh domain (§15);
 *   2. peristiwa sah diklaim sekali lewat `unique(provider, event_id)` —
 *      pengiriman ulang menjadi `Duplicate`, bukan penerapan kedua (§14);
 *   3. reference ID provider dicocokkan dengan `payment_charges`; referensi
 *      asing tetap tercatat sebagai `Unmatched` (diagnostik), bukan dibuang;
 *   4. status penyedia dipetakan dengan `forProviderStatus()`; `null`
 *      (mis. status tak dikenal) dan transisi mundur dari status yang sudah
 *      final diabaikan — TIDAK PERNAH dianggap pembayaran berhasil;
 *   5. hanya perubahan yang diterapkan yang dicatat ke jejak audit dan ke
 *      jurnal sinkronisasi (lewat pembaruan `Transaction`).
 *
 * Callback SELALU dijawab 2xx bila tanda tangannya sah — termasuk saat
 * isinya diabaikan — supaya penyedia tidak mengirim ulang tanpa henti.
 */
class ApplyPaymentWebhook
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly ?AuditRecorder $audit = null,
    ) {}

    /**
     * @param  array<string, string>  $headers  nama header lowercase → nilai
     */
    public function handle(string $provider, string $rawBody, array $headers = []): PaymentWebhookResult
    {
        $gateway = $this->gateways->for($provider);

        $event = $this->verify($provider, $gateway, $rawBody, $headers);

        $record = $this->claim($provider, $event);

        if ($record === null) {
            return $this->duplicate($provider, $event);
        }

        return $this->apply($provider, $event, $record);
    }

    /**
     * Verifikasi tanda tangan oleh adaptor. Peristiwa yang gagal diverifikasi
     * dicatat ke jejak audit (indikasi upaya pemalsuan) lalu dilempar ulang
     * supaya lapisan HTTP menjawab bukan-2xx dan tidak mengubah apa pun (§15).
     *
     * @param  array<string, string>  $headers
     */
    private function verify(string $provider, PaymentGateway $gateway, string $rawBody, array $headers): ProviderWebhookEvent
    {
        try {
            return $gateway->verifyAndParseWebhook($rawBody, $headers);
        } catch (InvalidWebhookSignature $exception) {
            $this->recorder(null)->record(
                AuditAction::PaymentWebhookRejected,
                reason: $exception->getMessage(),
                metadata: ['provider' => $provider],
            );

            throw $exception;
        }
    }

    /**
     * Perekam jejak audit dengan tenant yang baru diketahui setelah peristiwa
     * dicocokkan (webhook tidak membawa konteks tenant — §12).
     */
    private function recorder(?string $tenantId): AuditRecorder
    {
        return ($this->audit ?? new AuditRecorder)->withTenant($tenantId);
    }

    /**
     * Klaim peristiwa secara atomik. `null` berarti peristiwa ini sudah pernah
     * diterima (dijamin oleh unique constraint, sehingga aman terhadap dua
     * callback paralel — §14 idempotensi callback).
     */
    private function claim(string $provider, ProviderWebhookEvent $event): ?PaymentWebhookEvent
    {
        try {
            return PaymentWebhookEvent::create([
                'provider' => $provider,
                'event_id' => $event->eventId,
                'provider_reference' => $event->providerReference,
                'provider_status' => $event->status->value,
                'outcome' => PaymentWebhookOutcome::Received->value,
                'payload' => $event->raw,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function duplicate(string $provider, ProviderWebhookEvent $event): PaymentWebhookResult
    {
        $previous = PaymentWebhookEvent::query()
            ->with(['charge', 'transaction'])
            ->where('provider', $provider)
            ->where('event_id', $event->eventId)
            ->first();

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Duplicate,
            provider: $provider,
            eventId: $event->eventId,
            providerReference: $event->providerReference,
            providerStatus: $event->status,
            verification: $previous?->payment_verification,
            charge: $previous?->charge,
            transaction: $previous?->transaction,
        );
    }

    private function apply(
        string $provider,
        ProviderWebhookEvent $event,
        PaymentWebhookEvent $record,
    ): PaymentWebhookResult {
        $charge = PaymentCharge::query()
            ->with('transaction')
            ->where('provider', $provider)
            ->where('provider_reference', $event->providerReference)
            ->first();

        if ($charge === null) {
            $reason = 'Reference ID provider tidak dikenal pada instalasi ini.';

            $record->settle(PaymentWebhookOutcome::Unmatched, reason: $reason);

            $this->recorder(null)->record(
                AuditAction::PaymentWebhookUnmatched,
                reason: $reason,
                metadata: [
                    'provider' => $provider,
                    'provider_reference' => $event->providerReference,
                    'event_id' => $event->eventId,
                ],
            );

            return new PaymentWebhookResult(
                outcome: PaymentWebhookOutcome::Unmatched,
                provider: $provider,
                eventId: $event->eventId,
                providerReference: $event->providerReference,
                providerStatus: $event->status,
            );
        }

        $verification = PaymentVerification::forProviderStatus($event->status);

        if ($verification === null) {
            return $this->ignore(
                $record,
                $charge,
                'Status penyedia tidak dikenali — status lokal dibiarkan apa adanya.',
            );
        }

        $current = $charge->payment_verification;

        if ($current !== null && ! $current->canTransitionTo($verification)) {
            return $this->ignore(
                $record,
                $charge,
                "Peristiwa tidak mengubah status: \"{$current->value}\" tidak boleh berpindah ke \"{$verification->value}\".",
            );
        }

        return DB::transaction(function () use ($provider, $event, $record, $charge, $verification): PaymentWebhookResult {
            $charge->applyProviderStatus($event->status, $verification);

            $transaction = $charge->transaction;

            if ($transaction !== null && $transaction->payment_verification !== $verification->value) {
                $transaction->payment_verification = $verification->value;
                $transaction->save();
            }

            $record->settle(PaymentWebhookOutcome::Applied, charge: $charge, verification: $verification);

            $this->recorder($charge->tenant_id)->record(
                AuditAction::PaymentWebhookApplied,
                entityType: 'transaction',
                entityId: $transaction?->uuid,
                metadata: [
                    'provider' => $provider,
                    'provider_reference' => $event->providerReference,
                    'event_id' => $event->eventId,
                    'provider_status' => $event->status->value,
                    'payment_verification' => $verification->value,
                ],
            );

            return new PaymentWebhookResult(
                outcome: PaymentWebhookOutcome::Applied,
                provider: $provider,
                eventId: $event->eventId,
                providerReference: $event->providerReference,
                providerStatus: $event->status,
                verification: $verification,
                charge: $charge,
                transaction: $transaction,
            );
        });
    }

    /**
     * Peristiwa sah yang tidak mengubah status pembayaran. Dilaporkan sebagai
     * `Ignored` agar penyedia berhenti mengirim ulang, dan tetap tercatat untuk
     * pemeriksaan manual.
     */
    private function ignore(PaymentWebhookEvent $record, PaymentCharge $charge, string $reason): PaymentWebhookResult
    {
        $record->settle(
            PaymentWebhookOutcome::Ignored,
            charge: $charge,
            verification: $charge->payment_verification,
            reason: $reason,
        );

        return new PaymentWebhookResult(
            outcome: PaymentWebhookOutcome::Ignored,
            provider: $record->provider,
            eventId: $record->event_id,
            providerReference: $record->provider_reference,
            providerStatus: $record->provider_status,
            verification: $charge->payment_verification,
            charge: $charge,
            transaction: $charge->transaction,
        );
    }
}
