<?php

namespace Tests\Feature\Sync;

use App\Support\Sync\SyncChangeBatch;
use App\Support\Sync\SyncTransport;
use Throwable;

/**
 * Transport palsu untuk menguji loop retry/backoff tanpa jaringan.
 */
final class FakeSyncTransport implements SyncTransport
{
    /** @var list<list<array<string, mixed>>> */
    public array $calls = [];

    /** @var list<Throwable> */
    private array $failures = [];

    /** @var array<string, string> operation_id => status */
    private array $statuses = [];

    /** @var array<string, string|null> operation_id => error */
    private array $errors = [];

    /** @var list<SyncChangeBatch> halaman tarik yang akan dikembalikan berurutan */
    private array $pullPages = [];

    /** @var list<Throwable> */
    private array $pullFailures = [];

    /** @var list<array{token: string, cursor: string|null, limit: int}> */
    public array $pullCalls = [];

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function push(string $token, array $operations): array
    {
        $this->calls[] = $operations;

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return array_map(function (array $operation): array {
            $id = (string) $operation['operation_id'];

            return [
                'operation_id' => $id,
                'status' => $this->statuses[$id] ?? 'received',
                'server_sequence' => null,
                'error' => $this->errors[$id] ?? null,
            ];
        }, $operations);
    }

    public function failNext(Throwable $exception): self
    {
        $this->failures[] = $exception;

        return $this;
    }

    /**
     * Tarik perubahan: mengembalikan halaman yang disiapkan berurutan
     * (`setPullPages`), atau halaman kosong bila tidak ada.
     */
    public function pull(string $token, ?string $cursor = null, int $limit = 100): SyncChangeBatch
    {
        $this->pullCalls[] = ['token' => $token, 'cursor' => $cursor, 'limit' => $limit];

        if ($this->pullFailures !== []) {
            throw array_shift($this->pullFailures);
        }

        return array_shift($this->pullPages) ?? new SyncChangeBatch($cursor);
    }

    /**
     * @param  list<SyncChangeBatch>  $pages
     */
    public function setPullPages(array $pages): self
    {
        $this->pullPages = $pages;

        return $this;
    }

    public function failNextPull(Throwable $exception): self
    {
        $this->pullFailures[] = $exception;

        return $this;
    }

    public function setStatus(string $operationId, string $status, ?string $error = null): self
    {
        $this->statuses[$operationId] = $status;
        $this->errors[$operationId] = $error;

        return $this;
    }

    public function lastBatchSize(): int
    {
        return count($this->calls[count($this->calls) - 1] ?? []);
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
