<?php

namespace App\Support\Sync;

use App\Models\SyncChange;
use App\Support\Tenancy\TenantContext;
use RuntimeException;

/**
 * Sumber perubahan berbasis jurnal `sync_changes` (item Sprint 4.4).
 *
 * Kursor adalah `sequence` terakhir yang dikirim; pull berikutnya meminta
 * perubahan dengan `sequence` lebih besar. Urutan dijamin monoton sehingga
 * tidak ada perubahan yang terlewat atau terkirim ganda.
 */
final class DatabaseSyncChangeFeed implements SyncChangeFeed
{
    public function since(?string $cursor, int $limit): SyncChangeBatch
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            throw new RuntimeException('Tenant tidak diketahui saat menarik perubahan.');
        }

        $after = is_numeric($cursor) ? (int) $cursor : 0;

        $rows = SyncChange::query()
            ->forTenant($tenantId)
            ->afterSequence($after)
            ->orderBy('sequence')
            ->limit($limit)
            ->get();

        $changes = $rows->map(fn (SyncChange $change): array => [
            'sequence' => $change->sequence,
            'entity_type' => $change->entity_type,
            'entity_id' => $change->entity_id,
            'operation' => $change->operation->value,
            'payload' => $change->payload,
            'created_at' => $change->created_at?->toIso8601String(),
        ])->all();

        $lastSequence = $rows->last()?->sequence;
        $newCursor = $lastSequence !== null ? (string) $lastSequence : $cursor;

        $hasMore = $lastSequence !== null && SyncChange::query()
            ->forTenant($tenantId)
            ->afterSequence($lastSequence)
            ->exists();

        return new SyncChangeBatch($newCursor, $changes, $hasMore);
    }
}
