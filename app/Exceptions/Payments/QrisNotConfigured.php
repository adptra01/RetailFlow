<?php

namespace App\Exceptions\Payments;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * QRIS statis belum dikonfigurasi untuk toko (tenant) ini (FR-05).
 *
 * Kasir tidak boleh memproses pembayaran QRIS statis bila pemilik belum
 * mengunggah QRIS: tidak ada QR untuk dipindai dan tidak ada merchant tujuan.
 * Pesan sengaja bahasa Indonesia karena ditampilkan langsung di POS.
 */
final class QrisNotConfigured extends RuntimeException
{
    public static function forTenant(?string $tenantId): self
    {
        $suffix = $tenantId === null ? '' : " (tenant {$tenantId})";

        return new self('QRIS belum dikonfigurasi untuk toko ini'.$suffix.'. Minta pemilik mengunggah QRIS di Pengaturan Pembayaran.');
    }

    /**
     * Ini kesalahan PEMANGGIL (permintaan checkout memakai metode QRIS yang
     * belum disiapkan), bukan kegagalan server — jawab 422 seperti penolakan
     * domain penjualan lainnya (§18 "Informasi error validasi bila gagal").
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
