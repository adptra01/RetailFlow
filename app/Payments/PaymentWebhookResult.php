<?php

namespace App\Payments;

use App\Enums\PaymentVerification;
use App\Enums\PaymentWebhookOutcome;
use App\Models\PaymentCharge;
use App\Models\Transaction;

/**
 * Hasil pemrosesan satu callback/webhook pembayaran (plan §14).
 *
 * Dipakai bersama oleh handler webhook dan pengujiannya, sehingga keputusan
 * "apa yang terjadi pada peristiwa ini" dapat diperiksa tanpa membaca ulang
 * database. `toArray()` adalah kontrak jawaban HTTP untuk penyedia: sengaja
 * hanya memuat informasi yang sudah dikirim penyedia sendiri + hasil kita,
 * bukan data penjualan kita.
 */
final readonly class PaymentWebhookResult
{
    public function __construct(
        public PaymentWebhookOutcome $outcome,
        public string $provider,
        public string $eventId,
        public ?string $providerReference = null,
        public ?ProviderPaymentStatus $providerStatus = null,
        public ?PaymentVerification $verification = null,
        public ?PaymentCharge $charge = null,
        public ?Transaction $transaction = null,
    ) {}

    public function isDuplicate(): bool
    {
        return $this->outcome === PaymentWebhookOutcome::Duplicate;
    }

    public function changedPayment(): bool
    {
        return $this->outcome === PaymentWebhookOutcome::Applied;
    }

    /**
     * Jawaban JSON untuk penyedia (tanpa data internal kita).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'provider' => $this->provider,
            'event_id' => $this->eventId,
            'provider_reference' => $this->providerReference,
            'provider_status' => $this->providerStatus?->value,
            'payment_verification' => $this->verification?->value,
        ];
    }
}
