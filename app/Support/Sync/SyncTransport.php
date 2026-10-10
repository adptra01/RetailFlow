<?php

namespace App\Support\Sync;

/**
 * Transport pengiriman batch push ke server (§18).
 *
 * Seam ini memisahkan protokol dari loop retry/backoff sehingga logika
 * pengiriman ulang dapat diuji tanpa jaringan.
 */
interface SyncTransport
{
    /**
     * Kirim batch operasi ke server.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<array{operation_id: string, status: string, server_sequence?: int|null, error?: string|null}>
     *
     * @throws \App\Exceptions\Sync\SyncTransportException bila hasil tidak diketahui (timeout/koneksi/5xx)
     */
    public function push(string $token, array $operations): array;
}
