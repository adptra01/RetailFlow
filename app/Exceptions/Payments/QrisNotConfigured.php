<?php

namespace App\Exceptions\Payments;

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
}
