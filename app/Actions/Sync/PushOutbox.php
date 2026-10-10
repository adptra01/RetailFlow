<?php

namespace App\Actions\Sync;

use App\Exceptions\Sync\SyncTransportException;
use App\Models\SyncOutbox;
use App\Support\Sync\Outbox;
use App\Support\Sync\SyncPushSummary;
use App\Support\Sync\SyncTransport;
use Illuminate\Support\Collection;

/**
 * Use case pengiriman batch outbox ke server (Sprint 4 item 5 — "Retry dengan
 * backoff"; §11.3, §11.5).
 *
 * Alur satu putaran:
 * 1. Pulihkan entri `in_flight` yang terbengkalai (crash saat mengirim, §13).
 * 2. Pilih entri yang siap dicoba (jeda backoff sudah lewat).
 * 3. Tandai `in_flight`, kirim satu batch lewat `SyncTransport`.
 * 4. Terapkan hasil per operasi.
 *
 * Bila hasil pengiriman TIDAK DIKETAHUI (timeout/koneksi), operasi
 * dikembalikan ke `pending` (bukan `failed`) dan akan diulang dengan
 * backoff — perangkat tidak menganggapnya gagal hanya karena respons tak
 * diterima (§11.3). Pengiriman ulang aman karena `operation_id` unik di
 * server (§11.1).
 */
final class PushOutbox
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $deviceId,
        private readonly SyncTransport $transport,
        private readonly ?Outbox $outbox = null,
    ) {}

    public function handle(string $token, ?int $limit = null): SyncPushSummary
    {
        $outbox = $this->outbox ?? new Outbox($this->tenantId);

        $outbox->resetStaleInFlight($this->deviceId);

        /** @var Collection<int, SyncOutbox> $entries */
        $entries = $outbox->readyForAttempt($this->deviceId, $limit);

        if ($entries->isEmpty()) {
            return new SyncPushSummary;
        }

        $operations = $entries->map(fn (SyncOutbox $entry): array => [
            'operation_id' => $entry->operation_id,
            'entity_type' => $entry->entity_type,
            'entity_id' => $entry->entity_id,
            'operation_type' => $entry->operation_type->value,
            'payload' => $entry->payload,
            'local_sequence' => $entry->local_sequence,
        ])->all();

        foreach ($entries as $entry) {
            $entry->markInFlight();
        }

        try {
            $results = $this->transport->push($token, $operations);
        } catch (SyncTransportException $e) {
            foreach ($entries as $entry) {
                $entry->markRetryPending($e->getMessage());
            }

            return new SyncPushSummary(sent: $entries->count(), retrying: $entries->count());
        }

        return $this->applyResults($entries, $results);
    }

    /**
     * @param  Collection<int, SyncOutbox>  $entries
     * @param  list<array<string, mixed>>  $results
     */
    private function applyResults(Collection $entries, array $results): SyncPushSummary
    {
        $byId = collect($results)->keyBy('operation_id');

        $synced = 0;
        $failed = 0;
        $conflicted = 0;
        $retrying = 0;

        foreach ($entries as $entry) {
            $result = $byId->get($entry->operation_id);

            if (! is_array($result)) {
                $entry->markRetryPending('Respons server tidak memuat hasil operasi.');
                $retrying++;

                continue;
            }

            $status = (string) ($result['status'] ?? '');

            if (in_array($status, ['received', 'synced', 'duplicate'], true)) {
                $entry->markSynced();
                $synced++;

                continue;
            }

            if (in_array($status, ['rejected', 'invalid'], true)) {
                $entry->markFailed((string) ($result['error'] ?? 'Ditolak server.'));
                $failed++;

                continue;
            }

            if ($status === 'conflict') {
                $entry->markConflict((string) ($result['error'] ?? 'Konflik data.'));
                $conflicted++;

                continue;
            }

            $entry->markRetryPending('Status hasil tidak dikenal dari server.');
            $retrying++;
        }

        return new SyncPushSummary(
            sent: $entries->count(),
            synced: $synced,
            failed: $failed,
            conflicted: $conflicted,
            retrying: $retrying,
        );
    }
}
