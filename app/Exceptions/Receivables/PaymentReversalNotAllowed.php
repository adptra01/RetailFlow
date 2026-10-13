<?php

namespace App\Exceptions\Receivables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Koreksi/pembatalan pembayaran piutang tidak dapat dilakukan (FR-07).
 *
 * FR-07: "Koreksi harus dilakukan melalui transaksi koreksi yang memiliki
 * alasan dan jejak audit." Kondisi yang belum aman dikoreksi ditolak dengan
 * jelas, bukan dipaksa diam-diam.
 */
class PaymentReversalNotAllowed extends \RuntimeException
{
    public static function reasonRequired(): self
    {
        return new self('Koreksi pembayaran piutang memerlukan alasan.');
    }

    public static function notFound(): self
    {
        return new self('Pembayaran piutang yang akan dikoreksi tidak ditemukan.');
    }

    public static function receivableVoided(): self
    {
        return new self('Pembayaran tidak dapat dikoreksi karena piutangnya sudah dibatalkan.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
