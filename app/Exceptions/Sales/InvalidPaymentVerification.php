<?php

namespace App\Exceptions\Sales;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Status verifikasi pembayaran tidak boleh diberikan melalui jalur ini (FR-05).
 *
 * FR-05: "Sistem tidak boleh menyatakan pembayaran telah terverifikasi hanya
 * karena kasir menekan tombol 'dibayar'". Metode yang hanya berupa instruksi
 * pembayaran (transfer manual, QRIS statis) tidak punya jalur menuju
 * `verified`; status itu hanya boleh datang dari integrasi gateway resmi
 * (webhook bertanda tangan + rekonsiliasi) pada irisan berikutnya.
 */
class InvalidPaymentVerification extends \RuntimeException
{
    public static function notAllowedForInstructionOnly(string $method): self
    {
        return new self(
            'Pembayaran dengan metode "'.$method.'" tidak dapat ditandai terverifikasi otomatis; '
            .'status itu hanya boleh datang dari gateway pembayaran resmi.'
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
