<?php

namespace App\Payments;

use App\Exceptions\Payments\PaymentGatewayUnavailable;
use App\Support\Money\Money;

/**
 * Adaptor default: tidak ada payment gateway terpasang.
 *
 * Meniru `NullReceiptPrinter`: aplikasi wajib tetap berjalan tanpa gateway
 * (FR-01), tetapi TIDAK boleh berpura-pura ada verifikasi otomatis. Karena itu:
 *  - membuat pembayaran/refund → melempar `PaymentGatewayUnavailable` (gagal
 *    dengan jelas, bukan diam-diam mengembalikan "berhasil");
 *  - status → `Unknown` (bukan `Paid`);
 *  - webhook → melempar, sebab tanpa penyedia tidak ada tanda tangan yang bisa
 *    diverifikasi.
 */
final class NullPaymentGateway implements PaymentGateway
{
    public const KEY = 'none';

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function createCharge(PaymentChargeRequest $request): PaymentChargeResult
    {
        throw PaymentGatewayUnavailable::notConfigured();
    }

    public function fetchStatus(string $providerReference): PaymentStatusResult
    {
        return new PaymentStatusResult($providerReference, ProviderPaymentStatus::Unknown);
    }

    public function verifyAndParseWebhook(string $rawBody, array $headers): ProviderWebhookEvent
    {
        throw PaymentGatewayUnavailable::notConfigured();
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(string $providerReference, Money $amount): PaymentStatusResult
    {
        throw PaymentGatewayUnavailable::refundsNotSupported($this->key());
    }
}
