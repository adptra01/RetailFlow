<?php

namespace App\Actions\Payments;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Enums\PaymentReconciliationOutcome;
use App\Enums\PaymentVerification;
use App\Exceptions\Payments\UnknownPaymentProvider;
use App\Models\PaymentCharge;
use App\Models\PaymentReconciliationEntry;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentReconciliationReport;
use App\Payments\ProviderPaymentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mode B — Gateway: "Rekonsiliasi" + "Penanganan timeout" (plan §14).
 *
 * Setiap putaran memeriksa ulang charge yang belum final (status penyedia
 * masih `pending` atau `unknown` — mis. timeout putaran sebelumnya) ke
 * penyedia, lalu:
 *
 *  - status baru yang SAH diterapkan (charge + penjualan) dan dicatat audit;
 *  - status sama hanya memperbarui waktu pemeriksaan terakhir;
 *  - `unknown`/timeout TIDAK dianggap gagal: charge ditandai `unknown` agar
 *    dipoll lagi putaran berikutnya (bukan dibiarkan membusuk);
 *  - transisi yang melanggar aturan domain (mis. mencabut pembayaran yang
 *    sudah final) dilewati + DILAPORKAN untuk pemeriksaan manual;
 *  - total stop: putaran berhenti pada batas `limit`, dapat dijalankan ulang.
 *
 * Aman dijalankan bersamaan dengan callback/webhook: penerapan status bersifat
 * idempoten (status yang sama tidak mengubah apa pun; `canTransitionTo()` di
 * kedua sisi), dan entri putaran bersifat append-only.
 */
class ReconcilePaymentCharges
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly ?AuditRecorder $audit = null,
    ) {}

    /**
     * @param  array{provider?: string|null, tenantId?: string|null, limit?: int}  $options
     */
    public function handle(array $options = []): PaymentReconciliationReport
    {
        $runId = (string) Str::uuid7();
        $provider = $options['provider'] ?? null;
        $tenantId = $options['tenantId'] ?? null;

        $charges = $this->chargesToPoll($provider, $tenantId, $options['limit'] ?? 500);

        $counts = [
            PaymentReconciliationOutcome::Changed->value => 0,
            PaymentReconciliationOutcome::Unchanged->value => 0,
            PaymentReconciliationOutcome::Timeout->value => 0,
            PaymentReconciliationOutcome::Skipped->value => 0,
        ];
        $mismatches = [];

        foreach ($charges as $charge) {
            $entry = $this->reconcileCharge($runId, $charge);

            $counts[$entry->outcome->value]++;

            if ($entry->outcome === PaymentReconciliationOutcome::Skipped) {
                $mismatches[] = [
                    'payment_charge_id' => $entry->payment_charge_id,
                    'provider' => $entry->provider,
                    'provider_reference' => $entry->provider_reference,
                    'transaction_id' => $entry->transaction_id,
                    'reason' => $entry->reason,
                ];
            }
        }

        return new PaymentReconciliationReport(
            runId: $runId,
            provider: $provider,
            tenantId: $tenantId,
            polled: $charges->count(),
            changed: $counts[PaymentReconciliationOutcome::Changed->value],
            unchanged: $counts[PaymentReconciliationOutcome::Unchanged->value],
            timeout: $counts[PaymentReconciliationOutcome::Timeout->value],
            skipped: $counts[PaymentReconciliationOutcome::Skipped->value],
            mismatches: $mismatches,
        );
    }

    /**
     * Charge yang layak diperiksa ulang: penyedia belum menyatakan status
     * final (`pending`) atau tidak menjawab pada putaran sebelumnya (`unknown`).
     *
     * @return Collection<int, PaymentCharge>
     */
    private function chargesToPoll(?string $provider, ?string $tenantId, int $limit): Collection
    {
        $query = PaymentCharge::query()
            ->with('transaction')
            ->whereIn('status', [
                ProviderPaymentStatus::Pending->value,
                ProviderPaymentStatus::Unknown->value,
            ])
            ->orderBy('id')
            ->limit($limit);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($provider !== null) {
            $query->where('provider', $provider);
        }

        return $query->get();
    }

    private function reconcileCharge(string $runId, PaymentCharge $charge): PaymentReconciliationEntry
    {
        $statusBefore = $charge->status;
        $verificationBefore = $charge->payment_verification;

        try {
            $gateway = $this->gateways->for($charge->provider);
        } catch (UnknownPaymentProvider $exception) {
            // Penyedia tidak lagi terdaftar (mis. konfigurasi berubah): jangan
            // menyentuh apa pun; beri tahu operator lewat laporan.
            return $this->record(
                $runId, $charge,
                $statusBefore, $verificationBefore,
                $statusBefore, $verificationBefore,
                PaymentReconciliationOutcome::Skipped,
                $exception->getMessage(),
            );
        }

        $result = $gateway->fetchStatus($charge->provider_reference);

        if ($result->status === ProviderPaymentStatus::Unknown) {
            // Timeout / tidak dijawab. Bukan kegagalan dan BUKAN keberhasilan:
            // tandai `unknown` agar dipoll lagi, biarkan verifikasi apa adanya.
            $charge->applyProviderStatus(ProviderPaymentStatus::Unknown, null);

            return $this->record(
                $runId, $charge,
                $statusBefore, $verificationBefore,
                ProviderPaymentStatus::Unknown, $verificationBefore,
                PaymentReconciliationOutcome::Timeout,
                'Penyedia tidak menjawab atau mengembalikan status tak dikenal; status lokal dibiarkan (bukan gagal, bukan berhasil).',
            );
        }

        if ($result->status === $statusBefore) {
            $charge->update(['last_synced_at' => now()]);

            return $this->record(
                $runId, $charge,
                $statusBefore, $verificationBefore,
                $result->status, $verificationBefore,
                PaymentReconciliationOutcome::Unchanged,
                null,
            );
        }

        $verification = PaymentVerification::forProviderStatus($result->status);

        if ($verification === null) {
            // Pelindung defensif: setiap status (selain Unknown) sudah dipetakan
            // oleh forProviderStatus; bila suatu saat berubah, perlakukan seperti
            // timeout daripada menyimpulkan apa pun.
            $charge->applyProviderStatus(ProviderPaymentStatus::Unknown, null);

            return $this->record(
                $runId, $charge,
                $statusBefore, $verificationBefore,
                ProviderPaymentStatus::Unknown, $verificationBefore,
                PaymentReconciliationOutcome::Timeout,
                'Status penyedia tidak dapat dipetakan; status lokal dibiarkan.',
            );
        }

        if ($verificationBefore !== null && ! $verificationBefore->canTransitionTo($verification)) {
            $reason = sprintf(
                'Transisi tidak sah: "%s" tidak boleh berpindah ke "%s" menurut status penyedia (%s).',
                $verificationBefore->value,
                $verification->value,
                $result->status->value,
            );

            return $this->record(
                $runId, $charge,
                $statusBefore, $verificationBefore,
                $result->status, $verificationBefore,
                PaymentReconciliationOutcome::Skipped,
                $reason,
            );
        }

        $charge->applyProviderStatus($result->status, $verification);

        $transaction = $charge->transaction;

        if ($transaction !== null && $transaction->payment_verification !== $verification->value) {
            $transaction->payment_verification = $verification->value;
            $transaction->save();
        }

        ($this->audit ?? new AuditRecorder)->withTenant($charge->tenant_id)->record(
            AuditAction::PaymentReconciled,
            entityType: 'transaction',
            entityId: $transaction?->uuid,
            metadata: [
                'provider' => $charge->provider,
                'provider_reference' => $charge->provider_reference,
                'status_before' => $statusBefore->value,
                'status_after' => $result->status->value,
                'verification_before' => $verificationBefore?->value,
                'verification_after' => $verification->value,
            ],
        );

        return $this->record(
            $runId, $charge,
            $statusBefore, $verificationBefore,
            $result->status, $verification,
            PaymentReconciliationOutcome::Changed,
            null,
        );
    }

    private function record(
        string $runId,
        PaymentCharge $charge,
        ProviderPaymentStatus $statusBefore,
        ?PaymentVerification $verificationBefore,
        ProviderPaymentStatus $statusAfter,
        ?PaymentVerification $verificationAfter,
        PaymentReconciliationOutcome $outcome,
        ?string $reason,
    ): PaymentReconciliationEntry {
        return PaymentReconciliationEntry::create([
            'run_id' => $runId,
            'tenant_id' => $charge->tenant_id,
            'payment_charge_id' => $charge->getKey(),
            'transaction_id' => $charge->transaction_id,
            'provider' => $charge->provider,
            'provider_reference' => $charge->provider_reference,
            'status_before' => $statusBefore->value,
            'status_after' => $statusAfter->value,
            'verification_before' => $verificationBefore?->value,
            'verification_after' => $verificationAfter?->value,
            'outcome' => $outcome->value,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
