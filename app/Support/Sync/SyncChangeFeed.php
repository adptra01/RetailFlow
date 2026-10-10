<?php

namespace App\Support\Sync;

/**
 * Sumber perubahan server untuk sinkronisasi tarik (§11.3 langkah 9–10).
 *
 * Transport pull (item Sprint 4.3) bergantung pada kontrak ini; implementasi
 * kursor sebenarnya (item Sprint 4.4) akan menggantikan adaptor null tanpa
 * mengubah endpoint.
 */
interface SyncChangeFeed
{
    public function since(?string $cursor, int $limit): SyncChangeBatch;
}
