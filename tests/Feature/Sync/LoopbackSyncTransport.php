<?php

namespace Tests\Feature\Sync;

use App\Exceptions\Sync\SyncTransportException;
use App\Support\Sync\SyncChangeBatch;
use App\Support\Sync\SyncTransport;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Transport pengujian yang menembak endpoint nyata `/api/v1/sync/push`
 * DI DALAM proses yang sama (loopback), sehingga uji koneksi putus dapat
 * memverifikasi kedua sisi: loop retry/backoff klien DAN penerapan server
 * (`ReceiveSyncPush` + `ApplySyncOperations`).
 *
 * Selama `$failuresRemaining > 0`, pengiriman dianggap gagal koneksi
 * (timeout) — hasil tidak diketahui, sehingga operasi harus tetap tertunda.
 */
final class LoopbackSyncTransport implements SyncTransport
{
    public int $attempts = 0;

    public int $pullAttempts = 0;

    /** @var list<list<array<string, mixed>>> */
    public array $calls = [];

    public function __construct(
        public int $failuresRemaining = 0,
        public string $failureMessage = 'Koneksi ke server terputus.',
    ) {}

    public function goOffline(int $failures = 1): self
    {
        $this->failuresRemaining = $failures;

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function push(string $token, array $operations): array
    {
        $this->attempts++;
        $this->calls[] = $operations;

        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new SyncTransportException($this->failureMessage);
        }

        $request = Request::create('/api/v1/sync/push', 'POST', ['operations' => $operations]);
        $request->headers->set('Authorization', 'Bearer '.$token);
        $request->headers->set('Accept', 'application/json');

        $response = app(Kernel::class)->handle($request);

        if ($response->getStatusCode() >= 400) {
            throw new SyncTransportException('Server menolak permintaan (HTTP '.$response->getStatusCode().').');
        }

        /** @var array{results: list<array<string, mixed>>} $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return $decoded['results'];
    }

    /**
     * Tarik perubahan lewat endpoint nyata `/api/v1/sync/pull` (§11.3
     * langkah 9) sehingga uji menembus transport, autentikasi perangkat, dan
     * sumber kursor sekaligus.
     */
    public function pull(string $token, ?string $cursor = null, int $limit = 100): SyncChangeBatch
    {
        $this->pullAttempts++;

        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new SyncTransportException($this->failureMessage);
        }

        $request = Request::create('/api/v1/sync/pull', 'GET', array_filter([
            'cursor' => $cursor,
            'limit' => $limit,
        ], static fn ($value) => $value !== null));
        $request->headers->set('Authorization', 'Bearer '.$token);
        $request->headers->set('Accept', 'application/json');

        $response = app(Kernel::class)->handle($request);

        if ($response->getStatusCode() >= 400) {
            throw new SyncTransportException('Server menolak permintaan (HTTP '.$response->getStatusCode().').');
        }

        /** @var array{cursor?: string|null, changes?: list<array<string, mixed>>, has_more?: bool} $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return new SyncChangeBatch(
            cursor: $decoded['cursor'] ?? null,
            changes: $decoded['changes'] ?? [],
            hasMore: (bool) ($decoded['has_more'] ?? false),
        );
    }
}
