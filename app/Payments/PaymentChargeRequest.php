<?php

namespace App\Payments;

use App\Enums\PaymentMethod;
use App\Support\Money\Money;

/**
 * Permintaan pembuatan transaksi pembayaran ke penyedia (Mode B — Gateway,
 * plan §14 "Pembuatan transaksi pembayaran").
 *
 * `clientRequestId` adalah kunci idempotensi milik kita (FR-04): adaptor
 * penyedia WAJIB meneruskannya (atau memetakannya ke idempotency key penyedia)
 * sehingga percobaan ulang tidak membuat dua transaksi pembayaran.
 */
final readonly class PaymentChargeRequest
{
    public function __construct(
        public string $clientRequestId,
        public Money $amount,
        public string $currency,
        public ?PaymentMethod $method = null,
        public ?string $tenantId = null,
        public string $description = '',
    ) {}
}
