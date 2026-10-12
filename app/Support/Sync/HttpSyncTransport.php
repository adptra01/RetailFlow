<?php

namespace App\Support\Sync;

use App\Exceptions\Sync\SyncTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Transport HTTP default: `POST {server}/api/v1/sync/push` dengan token
 * perangkat (§18).
 *
 * Kegagalan koneksi/timeout dan respons gagal diperlakukan sebagai hasil
 * tidak diketahui → `SyncTransportException` (operasi dapat diulang, §11.3).
 */
final class HttpSyncTransport implements SyncTransport
{
    public function __construct(private readonly ?string $baseUrl = null) {}

    public function push(string $token, array $operations): array
    {
        $base = rtrim($this->baseUrl ?? (string) config('pos.sync.server_url'), '/');
        $timeout = (int) config('pos.sync.request_timeout', 10);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($timeout)
                ->post($base.'/api/v1/sync/push', ['operations' => $operations]);
        } catch (ConnectionException $e) {
            throw new SyncTransportException('Koneksi ke server gagal: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new SyncTransportException('Server gagal memproses permintaan (HTTP '.$response->status().').');
        }

        /** @var list<array<string, mixed>> $results */
        $results = $response->json('results', []);

        return $results;
    }

    /**
     * `GET {server}/api/v1/sync/pull?cursor=&limit=` (§18, §11.3 langkah 9).
     *
     * Kursor hanya bergerak bila halaman ini benar-benar diterima; kegagalan
     * koneksi/timeout/5xx melempar `SyncTransportException` sehingga perangkat
     * harus mencoba lagi dari kursor lama (§11.3 "Pengiriman ulang setelah
     * timeout harus aman").
     */
    public function pull(string $token, ?string $cursor = null, int $limit = 100): SyncChangeBatch
    {
        $base = rtrim($this->baseUrl ?? (string) config('pos.sync.server_url'), '/');
        $timeout = (int) config('pos.sync.request_timeout', 10);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($timeout)
                ->get($base.'/api/v1/sync/pull', array_filter([
                    'cursor' => $cursor,
                    'limit' => $limit,
                ], static fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw new SyncTransportException('Koneksi ke server gagal: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new SyncTransportException('Server gagal memproses permintaan (HTTP '.$response->status().').');
        }

        /** @var list<array<string, mixed>> $changes */
        $changes = $response->json('changes', []);

        return new SyncChangeBatch(
            cursor: $response->json('cursor'),
            changes: $changes,
            hasMore: (bool) $response->json('has_more', false),
        );
    }
}
