<?php

namespace App\Exceptions\Sales;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pembatalan transaksi (void) tidak dapat dilakukan (§17.4).
 *
 * §17.4: pembatalan memerlukan alasan dan izin, serta "koreksi pembayaran harus
 * tercatat". Kondisi yang belum aman untuk dibatalkan ditolak dengan jelas,
 * bukan diam-diam dipaksa.
 */
class VoidNotAllowed extends \RuntimeException
{
    public static function reasonRequired(): self
    {
        return new self('Pembatalan transaksi memerlukan alasan.');
    }

    public static function notFound(): self
    {
        return new self('Transaksi yang akan dibatalkan tidak ditemukan.');
    }

    public static function alreadyVoided(string $invoice): self
    {
        return new self("Transaksi {$invoice} sudah dibatalkan.");
    }

    public static function receivableHasPayments(string $invoice): self
    {
        return new self(
            "Transaksi {$invoice} sudah memiliki pembayaran piutang; koreksi piutang harus diselesaikan lebih dahulu sebelum pembatalan (§17.4)."
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
