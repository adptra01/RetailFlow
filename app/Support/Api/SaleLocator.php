<?php

namespace App\Support\Api;

use App\Models\Transaction;
use Illuminate\Support\Str;

/**
 * Mencari penjualan milik SATU tenant dari rujukan klien (§12, §18).
 *
 * Klien boleh merujuk penjualan dengan UUID sinkronisasi atau dengan id
 * numerik server; keduanya diterima. Yang tidak pernah bisa: melihat penjualan
 * tenant lain — setiap kueri menyaring `tenant_id` secara EKSPLISIT (bukan
 * hanya mengandalkan konteks/scope, yang mudah lupa diikat pada jalur API).
 */
final class SaleLocator
{
    public function find(string $tenantId, string $reference): ?Transaction
    {
        $query = Transaction::query()->where('tenant_id', $tenantId);

        if (Str::isUuid($reference)) {
            return $query->where('uuid', $reference)->first();
        }

        return ctype_digit($reference) ? $query->whereKey((int) $reference)->first() : null;
    }

    /**
     * Penjualan yang sudah ada untuk kunci idempotensi klien (§11.1/§18).
     */
    public function findByIdempotencyKey(string $tenantId, string $clientRequestId): ?Transaction
    {
        return Transaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_request_id', $clientRequestId)
            ->first();
    }

    /**
     * Pencarian UUID TANPA batas tenant — hanya untuk memeriksa bentrokan
     * identitas. Pemanggil WAJIB membandingkan `tenant_id` sendiri sebelum
     * mengembalikan apa pun ke klien (§12).
     */
    public function findByUuidAnyTenant(string $uuid): ?Transaction
    {
        return Transaction::query()
            ->withoutGlobalScopes()
            ->where('uuid', $uuid)
            ->first();
    }
}
