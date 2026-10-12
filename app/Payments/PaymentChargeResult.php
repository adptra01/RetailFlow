<?php

namespace App\Payments;

/**
 * Hasil pembuatan transaksi pembayaran di penyedia (plan §14 "Penyimpanan
 * reference ID provider").
 *
 * `providerReference` disimpan pada transaksi lokal agar callback/webhook dan
 * rekonsiliasi dapat mencocokkan pembayaran dengan penjualan kita. `raw`
 * menyimpan respons mentah untuk audit/diagnostik — perhatikan: adaptor TIDAK
 * BOLEH menaruh secret di sini (plan §15 "Audit log tidak menyimpan secret").
 */
final readonly class PaymentChargeResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $providerReference,
        public ProviderPaymentStatus $status,
        public array $raw = [],
        public ?string $qrPayload = null,
        public ?string $redirectUrl = null,
    ) {}
}
