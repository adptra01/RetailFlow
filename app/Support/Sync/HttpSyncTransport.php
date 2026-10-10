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
}
