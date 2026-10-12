<?php

namespace App\Payments;

/**
 * Status terkini sebuah transaksi pembayaran di penyedia.
 *
 * Dipakai oleh rekonsiliasi (plan §14 "Rekonsiliasi") untuk membandingkan
 * catatan lokal dengan catatan penyedia.
 */
final readonly class PaymentStatusResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $providerReference,
        public ProviderPaymentStatus $status,
        public array $raw = [],
    ) {}
}
