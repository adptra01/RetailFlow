<?php

namespace App\Support\Sync;

/**
 * Adaptor perubahan kosong (default sampai item Sprint 4.4 "Cursor
 * sinkronisasi").
 *
 * Endpoint pull tetap berfungsi dan mengembalikan kontrak yang benar tanpa
 * mengarang semantik kursor. Menggantinya cukup dengan binding baru pada
 * container.
 */
final class NullSyncChangeFeed implements SyncChangeFeed
{
    public function since(?string $cursor, int $limit): SyncChangeBatch
    {
        return new SyncChangeBatch($cursor, [], false);
    }
}
