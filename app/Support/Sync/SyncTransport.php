<?php

namespace App\Support\Sync;

use App\Exceptions\Sync\SyncTransportException;

/**
 * Transport sinkronisasi ke server (§18).
 *
 * Seam ini memisahkan protokol dari loop retry/backoff serta dari strategi
 * kursor, sehingga logika pengiriman ulang dan penerapan perubahan dapat
 * diuji tanpa jaringan.
 */
interface SyncTransport
{
    /**
     * Kirim batch operasi ke server (§11.3 langkah 3).
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<array{operation_id: string, status: string, server_sequence?: int|null, error?: string|null}>
     *
     * @throws SyncTransportException bila hasil tidak diketahui (timeout/koneksi/5xx)
     */
    public function push(string $token, array $operations): array;

    /**
     * Tarik perubahan server sejak kursor terakhir (§11.3 langkah 9).
     *
     * @throws SyncTransportException bila hasil tidak diketahui (timeout/koneksi/5xx)
     */
    public function pull(string $token, ?string $cursor = null, int $limit = 100): SyncChangeBatch;
}
