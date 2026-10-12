<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Tanda tangan callback/webhook tidak valid atau tidak dapat diverifikasi
 * (plan §15 "Webhook memvalidasi tanda tangan dan mencegah replay").
 *
 * Peristiwa yang gagal verifikasi TIDAK BOLEH mengubah status pembayaran.
 */
final class InvalidWebhookSignature extends RuntimeException
{
    public static function forProvider(string $provider): self
    {
        return new self("Tanda tangan webhook dari \"{$provider}\" tidak sah; peristiwa diabaikan.");
    }

    public static function missingHeader(string $provider, string $header): self
    {
        return new self("Webhook dari \"{$provider}\" tidak menyertakan header tanda tangan \"{$header}\".");
    }

    public static function staleTimestamp(string $provider): self
    {
        return new self("Webhook dari \"{$provider}\" memiliki stempel waktu di luar toleransi; kemungkinan replay.");
    }
}
