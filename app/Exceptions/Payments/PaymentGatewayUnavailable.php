<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Operasi gateway diminta padahal tidak ada penyedia yang dikonfigurasi
 * (atau penyedia tersebut tidak mendukung operasinya).
 *
 * Ini BUKAN kegagalan pembayaran: pembayaran dengan pencatatan kasir (tunai,
 * transfer manual, QRIS statis) tetap sah tanpa gateway (FR-01). Pesan sengaja
 * bahasa Indonesia karena dapat ditampilkan di POS/panel pengaturan.
 */
final class PaymentGatewayUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Belum ada payment gateway yang dikonfigurasi. Pembayaran tetap dapat dicatat oleh kasir tanpa verifikasi otomatis.');
    }

    public static function providerNotConfigured(string $provider): self
    {
        return new self("Payment gateway \"{$provider}\" belum dikonfigurasi (kredensial tidak tersedia).");
    }

    public static function refundsNotSupported(string $provider): self
    {
        return new self("Payment gateway \"{$provider}\" tidak mendukung refund melalui sistem ini.");
    }
}
