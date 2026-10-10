<?php

namespace Tests\Feature\Sync;

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
