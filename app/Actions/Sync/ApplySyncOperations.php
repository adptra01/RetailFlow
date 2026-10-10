<?php

namespace App\Actions\Sync;

use App\Enums\SyncInboxStatus;
use App\Models\SyncInbox;
use App\Support\Sync\SyncApplicationResult;
use App\Support\Sync\SyncOperationApplier;
use Illuminate\Support\Facades\DB;

/**
 * Use case penerapan operasi inbox ke domain (Sprint 4 item 6 —
 * "Idempotensi server"; §11.3 langkah 6–7).
 *
 * Setiap operasi diterapkan dalam transaksinya sendiri sehingga satu operasi
 * bermasalah tidak membatalkan operasi lain dalam batch. Baris inbox dikunci
 * (`lockForUpdate`) dan status diperiksa ulang, sehingga penerapan ganda
 * (pengiriman ulang/paralel) tidak menggandakan efek domain.
 *
 * Hasil per operasi memakai kosakata klien: `applied`, `rejected`,
 * `conflict`, atau `received` (tersimpan, belum ada handler).
 */
final class ApplySyncOperations
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $deviceId,
        private readonly ?SyncOperationApplier $applier = null,
    ) {}

    /**
     * Terapkan operasi (opsional dibatasi `$operationIds`) lalu kembalikan
     * status akhir per `operation_id`.
     *
     * @param  list<string>|null  $operationIds
     * @return array<string, array{status: string, entity_id: string|null, error: string|null}>
     */
    public function handle(?array $operationIds = null, ?int $limit = null): array
    {
        $applier = $this->applier ?? app(SyncOperationApplier::class);

        $pending = SyncInbox::query()
            ->forDevice($this->deviceId)
            ->where('tenant_id', $this->tenantId)
            ->withStatus(SyncInboxStatus::Received)
            ->when($operationIds !== null, fn ($query) => $query->whereIn('operation_id', $operationIds))
            ->orderBy('id')
            ->limit($limit ?? (int) config('pos.sync.max_batch', 500))
            ->pluck('operation_id')
            ->all();

        foreach ($pending as $operationId) {
            $this->applyOne($operationId, $applier);
        }

        return $this->statusesFor($operationIds);
    }

    /**
     * Status akhir untuk sejumlah `operation_id` (termasuk yang sudah selesai
     * pada putaran sebelumnya).
     *
     * @param  list<string>|null  $operationIds
     * @return array<string, array{status: string, entity_id: string|null, error: string|null}>
     */
    public function statusesFor(?array $operationIds = null): array
    {
        return SyncInbox::query()
            ->forDevice($this->deviceId)
            ->where('tenant_id', $this->tenantId)
            ->when($operationIds !== null, fn ($query) => $query->whereIn('operation_id', $operationIds))
            ->get()
            ->mapWithKeys(fn (SyncInbox $row): array => [
                $row->operation_id => [
                    'status' => $row->status->value,
                    'entity_id' => $row->applied_entity_id,
                    'error' => $row->error,
                ],
            ])
            ->all();
    }

    private function applyOne(string $operationId, SyncOperationApplier $applier): void
    {
        DB::transaction(function () use ($operationId, $applier): void {
            $row = SyncInbox::query()
                ->where('operation_id', $operationId)
                ->lockForUpdate()
                ->first();

            // Sudah diterapkan/ditolak oleh proses lain → lewati (idempoten).
            if ($row === null || ! $row->isReceived()) {
                return;
            }

            $result = $applier->apply($row);

            $this->persist($row, $result);
        });
    }

    private function persist(SyncInbox $row, SyncApplicationResult $result): void
    {
        match ($result->status) {
            SyncInboxStatus::Applied => $row->markApplied($result->entityId),
            SyncInboxStatus::Rejected => $row->markRejected($result->error ?? 'Ditolak server.'),
            SyncInboxStatus::Conflict => $row->markConflict($result->error ?? 'Konflik data.'),
            // Tidak ada handler: biarkan `received` agar dapat diproses ulang.
            SyncInboxStatus::Received => null,
        };
    }
}
