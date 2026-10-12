<?php

namespace App\Exceptions\Sales;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retur penjualan tidak dapat dilakukan (FR-08, §17.4).
 *
 * §17.4 menuntut retur memerlukan alasan dan izin, serta pengembalian stok
 * hanya sesuai kebijakan. Kondisi yang tidak aman ditolak dengan jelas,
 * bukan dipaksa atau didiamkan.
 */
class ReturnNotAllowed extends \RuntimeException
{
    public static function reasonRequired(): self
    {
        return new self('Retur penjualan memerlukan alasan.');
    }

    public static function notFound(): self
    {
        return new self('Transaksi yang akan diretur tidak ditemukan.');
    }

    public static function voidedSale(string $invoice): self
    {
        return new self("Transaksi {$invoice} sudah dibatalkan (void); retur tidak berlaku untuk transaksi batal.");
    }

    public static function noItems(): self
    {
        return new self('Retur harus memuat minimal satu barang.');
    }

    public static function invalidQuantity(): self
    {
        return new self('Jumlah barang yang diretur tidak valid.');
    }

    public static function exceedsSoldQuantity(string $product, int $max): self
    {
        return new self(
            "Jumlah retur untuk {$product} melebihi yang masih dapat diretur (maks: {$max})."
        );
    }

    public static function itemNotInSale(int $itemId): self
    {
        return new self("Baris penjualan #{$itemId} tidak termasuk dalam transaksi ini.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
