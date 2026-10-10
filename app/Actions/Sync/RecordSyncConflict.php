<?php

namespace App\Actions\Sync;

use App\Enums\SyncConflictStatus;
use App\Enums\SyncConflictType;
use App\Models\SyncConflict;
use App\Models\SyncInbox;

/**
 * Mencatat kasus konflik sinkronisasi (§11.4 "Konflik dibuat sebagai kasus
 * yang perlu diselesaikan").
 *
 * Idempoten: satu kasus per `(operation_id, type)` — pengiriman ulang operasi
 * yang sama tidak menggandakan kasus. Detail terbaru menimpa detail lama
 * (mis. percobaan ulang dengan angka stok yang berubah).
 */
final class RecordSyncConflict
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function handle(
        SyncInbox $operation,
        SyncConflictType $type,
        array $details,
        ?string $entityId = null,
    ): SyncConflict {
        return SyncConflict::query()->updateOrCreate(
            [
                'operation_id' => $operation->operation_id,
                'type' => $type->value,
            ],
            [
                'tenant_id' => $operation->tenant_id,
                'device_id' => $operation->device_id,
                'status' => SyncConflictStatus::Open->value,
                'entity_type' => $operation->entity_type,
                'entity_id' => $entityId ?? $operation->entity_id,
                'details' => $details,
            ],
        );
    }
}
