<?php

namespace App\Support\Api;

use App\Models\Receivable;
use App\Models\ReceivablePayment;
use Illuminate\Support\Str;

/**
 * Mencari piutang & pembayaran piutang milik SATU tenant (§12, §18).
 *
 * Rujukan klien boleh UUID sinkronisasi atau id numerik server. Setiap kueri
 * menyaring `tenant_id` secara eksplisit — tidak mengandalkan konteks, yang
 * bisa saja belum/keliru terikat pada jalur API.
 */
final class ReceivableLocator
{
    public function find(string $tenantId, string $reference): ?Receivable
    {
        $query = Receivable::query()->where('tenant_id', $tenantId);

        if (Str::isUuid($reference)) {
            return $query->where('uuid', $reference)->first();
        }

        return ctype_digit($reference) ? $query->whereKey((int) $reference)->first() : null;
    }

    /**
     * Pembayaran yang sudah tercatat untuk kunci idempotensi klien (FR-07).
     */
    public function findByIdempotencyKey(string $tenantId, string $clientRequestId): ?ReceivablePayment
    {
        return ReceivablePayment::query()
            ->where('tenant_id', $tenantId)
            ->where('client_request_id', $clientRequestId)
            ->first();
    }
}
