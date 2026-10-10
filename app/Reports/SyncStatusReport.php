<?php

namespace App\Reports;

use App\Enums\SyncConflictStatus;
use App\Enums\SyncStatus;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Status sinkronisasi untuk UI (Sprint 4 item 8 — "Status sinkronisasi UI";
 * §11.5 "Sistem menampilkan jumlah operasi tertunda").
 *
 * Read model murni: tidak menulis apa pun. Menghitung antrean outbox per
 * status, kasus konflik terbuka, dan kapan sinkronisasi terakhir berhasil,
 * lalu merangkumnya menjadi satu `state` yang mudah ditampilkan:
 *
 * - `attention` — ada operasi gagal atau konflik terbuka yang butuh pengguna;
 * - `pending`   — ada operasi menunggu/dikirim;
 * - `synced`    — antrean bersih dan pernah berhasil sinkron;
 * - `unknown`   — belum ada aktivitas sinkronisasi.
 */
class SyncStatusReport
{
    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?string $deviceId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $counts = $this->outboxCounts();
        $openConflicts = $this->openConflictCount();

        $pending = $counts[SyncStatus::Pending->value];
        $inFlight = $counts[SyncStatus::InFlight->value];
        $queued = $pending + $inFlight;
        $failed = $counts[SyncStatus::Failed->value];
        $conflict = $counts[SyncStatus::Conflict->value];
        $synced = $counts[SyncStatus::Synced->value];
        $total = array_sum($counts);

        return [
            'device_id' => $this->deviceId,
            'state' => $this->resolveState($queued, $failed, $conflict, $openConflicts, $synced, $total),
            'pending' => $pending,
            'in_flight' => $inFlight,
            'queued' => $queued,
            'failed' => $failed,
            'conflict' => $conflict,
            'synced' => $synced,
            'total' => $total,
            // §11.5: jumlah operasi tertunda yang terlihat pengguna.
            'pending_user_visible' => $queued + $failed + $conflict,
            'open_conflicts' => $openConflicts,
            'last_synced_at' => $this->lastSyncedAt()?->toIso8601String(),
            'oldest_pending_at' => $this->oldestPendingAt()?->toIso8601String(),
        ];
    }

    /**
     * Daftar kasus konflik terbuka (§11.3 langkah 11).
     *
     * @return list<array<string, mixed>>
     */
    public function openConflicts(int $limit = 50): array
    {
        return $this->conflictQuery()
            ->where('status', SyncConflictStatus::Open->value)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (SyncConflict $conflict): array => [
                'id' => $conflict->id,
                'uuid' => $conflict->uuid,
                'type' => $conflict->type->value,
                'type_label' => $conflict->type->label(),
                'device_id' => $conflict->device_id,
                'operation_id' => $conflict->operation_id,
                'entity_id' => $conflict->entity_id,
                'details' => $conflict->details,
                'created_at' => $conflict->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Operasi yang belum tersinkron (pending/in_flight/failed/conflict).
     *
     * @return list<array<string, mixed>>
     */
    public function unsyncedOperations(int $limit = 50): array
    {
        return $this->outboxQuery()
            ->whereIn('sync_status', [
                SyncStatus::Pending->value,
                SyncStatus::InFlight->value,
                SyncStatus::Failed->value,
                SyncStatus::Conflict->value,
            ])
            ->orderBy('local_sequence')
            ->limit($limit)
            ->get()
            ->map(fn (SyncOutbox $entry): array => [
                'operation_id' => $entry->operation_id,
                'entity_type' => $entry->entity_type,
                'entity_id' => $entry->entity_id,
                'operation_type' => (string) $entry->operation_type,
                'sync_status' => $entry->sync_status->value,
                'sync_status_label' => $entry->sync_status->label(),
                'retry_count' => $entry->retry_count,
                'last_error' => $entry->last_error,
                'next_attempt_at' => $entry->nextAttemptAt()->toIso8601String(),
                'created_at' => $entry->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    public function device(): ?Device
    {
        if ($this->deviceId === null) {
            return null;
        }

        return Device::query()->find($this->deviceId);
    }

    /**
     * @return array<string, int>
     */
    private function outboxCounts(): array
    {
        $counts = [];

        foreach (SyncStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $rows = $this->outboxQuery()
            ->select('sync_status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('sync_status')
            ->pluck('aggregate', 'sync_status');

        foreach ($rows as $status => $aggregate) {
            $counts[(string) $status] = (int) $aggregate;
        }

        return $counts;
    }

    private function openConflictCount(): int
    {
        return $this->conflictQuery()
            ->where('status', SyncConflictStatus::Open->value)
            ->count();
    }

    private function lastSyncedAt(): ?Carbon
    {
        $value = $this->outboxQuery()
            ->where('sync_status', SyncStatus::Synced->value)
            ->max('updated_at');

        return $value === null ? null : Carbon::parse($value);
    }

    private function oldestPendingAt(): ?Carbon
    {
        $value = $this->outboxQuery()
            ->whereIn('sync_status', [SyncStatus::Pending->value, SyncStatus::InFlight->value])
            ->min('created_at');

        return $value === null ? null : Carbon::parse($value);
    }

    private function resolveState(
        int $queued,
        int $failed,
        int $conflict,
        int $openConflicts,
        int $synced,
        int $total,
    ): string {
        if ($failed > 0 || $conflict > 0 || $openConflicts > 0) {
            return 'attention';
        }

        if ($queued > 0) {
            return 'pending';
        }

        return $total > 0 && $synced > 0 ? 'synced' : 'unknown';
    }

    /**
     * @return Builder<SyncOutbox>
     */
    private function outboxQuery(): Builder
    {
        $query = SyncOutbox::query();

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        if ($this->deviceId !== null) {
            $query->forDevice($this->deviceId);
        }

        return $query;
    }

    /**
     * @return Builder<SyncConflict>
     */
    private function conflictQuery(): Builder
    {
        $query = SyncConflict::query();

        if ($this->tenantId !== null) {
            $query->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId);
        }

        if ($this->deviceId !== null) {
            $query->where('device_id', $this->deviceId);
        }

        return $query;
    }
}
