<?php

namespace App\Actions\Sync;

use App\Enums\SyncInboxStatus;
use App\Models\SyncInbox;
use Illuminate\Support\Facades\DB;

/**
 * Use case penerimaan batch push (§11.3 langkah 4–7).
 *
 * - Server menyimpan operasi yang sah dalam SATU transaksi (§11.3 langkah 6).
 * - `operation_id` unik membuat pengiriman ulang idempoten: operasi yang
 *   sudah pernah diterima dikembalikan sebagai `duplicate` tanpa baris baru
 *   (§11.1 "Sinkronisasi harus aman terhadap pengiriman ulang").
 * - `tenant_id`/`device_id` diambil dari identitas perangkat terautentikasi,
 *   bukan dari payload (§12).
 */
final class ReceiveSyncPush
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $deviceId,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{results: list<array<string, mixed>>, accepted: int, duplicates: int}
     */
    public function handle(array $operations): array
    {
        return DB::transaction(function () use ($operations): array {
            $results = [];
            $accepted = 0;
            $duplicates = 0;

            foreach ($operations as $operation) {
                $existing = SyncInbox::query()
                    ->where('operation_id', $operation['operation_id'])
                    ->first();

                if ($existing !== null) {
                    $duplicates++;
                    $results[] = [
                        'operation_id' => $operation['operation_id'],
                        'status' => 'duplicate',
                        'server_sequence' => $existing->serverSequence(),
                    ];

                    continue;
                }

                $entry = SyncInbox::create([
                    'operation_id' => $operation['operation_id'],
                    'tenant_id' => $this->tenantId,
                    'device_id' => $this->deviceId,
                    'entity_type' => $operation['entity_type'],
                    'entity_id' => $operation['entity_id'],
                    'operation_type' => $operation['operation_type'],
                    'payload' => $operation['payload'] ?? [],
                    'local_sequence' => $operation['local_sequence'],
                    'status' => SyncInboxStatus::Received,
                    'received_at' => now(),
                ]);

                $accepted++;
                $results[] = [
                    'operation_id' => $operation['operation_id'],
                    'status' => 'received',
                    'server_sequence' => $entry->serverSequence(),
                ];
            }

            return ['results' => $results, 'accepted' => $accepted, 'duplicates' => $duplicates];
        });
    }
}
