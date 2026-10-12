<?php

namespace App\Actions\Payments;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Models\PaymentCharge;
use App\Models\Transaction;
use App\Models\User;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentChargeRequest;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Mode B — Gateway: "Pembuatan transaksi pembayaran" + "Penyimpanan reference
 * ID provider" (plan §14).
 *
 * Satu-satunya tempat aplikasi membuat transaksi di penyedia dan menyimpan
 * reference ID-nya. Aturan yang dijaga di sini:
 *
 *  - idempoten terhadap `clientRequestId` (FR-04): kunci yang sama tidak pernah
 *    membuat dua transaksi pembayaran, bahkan sebelum menyentuh penyedia;
 *  - kredensial tidak pernah dibaca dari database lokal (FR-05) — adaptor yang
 *    membacanya dari environment lewat `config('pos.payments.credentials')`;
 *  - status yang dikembalikan penyedia dipetakan dengan
 *    `PaymentVerification::forProviderStatus()`; hanya `paid` yang boleh
 *    membuat penjualan terverifikasi (FR-05);
 *  - penyedia belum terpasang = kegagalan yang jelas
 *    (`PaymentGatewayUnavailable`), bukan transaksi palsu (FR-01).
 */
class CreatePaymentCharge
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly ?AuditRecorder $audit = null,
    ) {}

    /**
     * @param  string  $clientRequestId  kunci idempotensi milik kita (FR-04)
     * @param  string|null  $provider  kunci penyedia; null = penyedia default
     */
    public function handle(
        Transaction $transaction,
        Money $amount,
        string $clientRequestId,
        ?PaymentMethod $method = null,
        ?string $provider = null,
        ?User $actor = null,
        string $currency = 'IDR',
        string $description = '',
    ): PaymentCharge {
        $providerKey = $provider ?? $this->gateways->defaultKey();

        $existing = $this->findExisting($providerKey, $clientRequestId);

        if ($existing !== null) {
            // Percobaan ulang: kembalikan transaksi yang sama tanpa memanggil
            // penyedia lagi (FR-04, "aman terhadap pengiriman ulang").
            return $existing;
        }

        $gateway = $this->gateways->for($providerKey);

        $result = $gateway->createCharge(new PaymentChargeRequest(
            clientRequestId: $clientRequestId,
            amount: $amount,
            currency: $currency,
            method: $method,
            tenantId: $transaction->tenant_id,
            description: $description,
        ));

        $verification = PaymentVerification::forProviderStatus($result->status);

        $recorder = ($this->audit ?? new AuditRecorder)->withTenant($transaction->tenant_id);

        return DB::transaction(function () use (
            $transaction, $gateway, $result, $clientRequestId, $amount, $currency, $verification, $actor, $recorder,
        ): PaymentCharge {
            $charge = PaymentCharge::create([
                'tenant_id' => $transaction->tenant_id,
                'transaction_id' => $transaction->getKey(),
                'provider' => $gateway->key(),
                'provider_reference' => $result->providerReference,
                'client_request_id' => $clientRequestId,
                'status' => $result->status->value,
                'payment_verification' => $verification?->value,
                'amount_minor' => $amount->minor(),
                'currency' => $currency,
                'payload' => $result->raw,
                'last_synced_at' => now(),
            ]);

            $this->syncTransactionVerification($transaction, $verification);

            ($recorder)->record(
                AuditAction::PaymentChargeCreated,
                actor: $actor,
                entityType: 'transaction',
                entityId: $transaction->uuid,
                metadata: [
                    'provider' => $gateway->key(),
                    'provider_reference' => $result->providerReference,
                    'amount_minor' => $amount->minor(),
                    'currency' => $currency,
                    'status' => $result->status->value,
                    'payment_verification' => $verification?->value,
                    'client_request_id' => $clientRequestId,
                ],
            );

            return $charge;
        });
    }

    private function findExisting(string $provider, string $clientRequestId): ?PaymentCharge
    {
        return PaymentCharge::query()
            ->where('provider', $provider)
            ->where('client_request_id', $clientRequestId)
            ->first();
    }

    /**
     * Selaraskan status verifikasi penjualan dengan jawaban penyedia.
     *
     * Status yang sudah "selesai" (`isSettled()`) tidak pernah diturunkan:
     * percobaan pembuatan transaksi kedua tidak boleh membatalkan verifikasi
     * yang sudah sah.
     */
    private function syncTransactionVerification(Transaction $transaction, ?PaymentVerification $fromProvider): void
    {
        $current = $transaction->payment_verification;
        $currentEnum = $current === null ? null : PaymentVerification::tryFrom((string) $current);

        if ($currentEnum?->isSettled() === true) {
            return;
        }

        $target = $fromProvider ?? PaymentVerification::AwaitingVerification;

        if ($currentEnum === $target) {
            return;
        }

        $transaction->payment_verification = $target->value;
        $transaction->save();
    }
}
