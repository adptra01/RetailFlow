<?php

namespace App\Support\Sync;

use App\Enums\SyncOperationType;
use App\Enums\SyncStatus;
use App\Models\SyncOutbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Antrean outbox sinkronisasi (§11.1–§11.3).
 *
 * Panggil `enqueue()` DI DALAM transaksi yang sama dengan perubahan domain
 * agar operasi tidak hilang saat crash (transactional outbox). Bila
 * transaksi domain rollback, entri outbox ikut hilang.
 *
 * Operasi lokal tidak boleh menunggu server (§11.1): enqueue hanya menulis
 * baris `pending`; pengiriman dilakukan batch oleh lapisan sinkronisasi.
 */
final class Outbox
{
    public function __construct(private readonly ?string $tenantId = null) {}

    /**
     * Tambahkan satu operasi ke antrean. Aman dipanggil berulang dalam
     * transaksi; `local_sequence` per perangkat dihitung atomik.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        string $deviceId,
        string $entityType,
        string $entityId,
        SyncOperationType $operationType,
        array $payload = [],
        ?string $operationId = null,
    ): SyncOutbox {
        return SyncOutbox::create([
            'operation_id' => $operationId ?? (string) Str::uuid7(),
            'tenant_id' => $this->resolveTenantId(),
            'device_id' => $deviceId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation_type' => $operationType->value,
            'payload' => $payload,
            'local_sequence' => $this->nextSequence($deviceId),
            'sync_status' => SyncStatus::Pending,
            'retry_count' => 0,
        ]);
    }

    /**
     * Nomor urut berikutnya untuk perangkat (mulai dari 1).
     */
    public function nextSequence(string $deviceId): int
    {
        $max = SyncOutbox::query()
            ->where('device_id', $deviceId)
            ->lockForUpdate()
            ->max('local_sequence');

        return (int) $max + 1;
    }

    /**
     * Entri yang siap dikirim (belum "in flight"), urut `local_sequence`.
     *
     * @return Collection<int, SyncOutbox>
     */
    public function pending(string $deviceId, ?int $limit = null): Collection
    {
        return SyncOutbox::query()
            ->forDevice($deviceId)
            ->pending()
            ->orderBy('local_sequence')
            ->limit($limit ?? $this->batchSize())
            ->get();
    }

    /**
     * Entri yang boleh dikirim ulang: pending/failed dengan sisa percobaan.
     *
     * @return Collection<int, SyncOutbox>
     */
    public function retryable(string $deviceId, ?int $limit = null): Collection
    {
        return SyncOutbox::query()
            ->forDevice($deviceId)
            ->retryable()
            ->orderBy('local_sequence')
            ->limit($limit ?? $this->batchSize())
            ->get();
    }

    /**
     * Jumlah operasi tertunda yang terlihat pengguna (§11.5).
     */
    public function pendingCount(string $deviceId): int
    {
        return SyncOutbox::query()
            ->forDevice($deviceId)
            ->whereIn('sync_status', [
                SyncStatus::Pending->value,
                SyncStatus::InFlight->value,
                SyncStatus::Failed->value,
                SyncStatus::Conflict->value,
            ])
            ->count();
    }

    public function unsyncedCount(string $deviceId): int
    {
        return $this->pendingCount($deviceId);
    }

    public function batchSize(): int
    {
        return (int) config('pos.offline.batch_size', 100);
    }

    private function resolveTenantId(): string
    {
        $tenantId = $this->tenantId ?? app(TenantContext::class)->id();

        if ($tenantId === null) {
            throw new \RuntimeException('Tenant tidak diketahui saat menulis outbox.');
        }

        return $tenantId;
    }
}
