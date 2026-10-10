<?php

namespace Tests\Feature\Sync;

use App\Actions\Sync\PushOutbox;
use App\Enums\SyncOperationType;
use App\Enums\SyncStatus;
use App\Exceptions\Sync\SyncTransportException;
use App\Models\Device;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Support\Sync\HttpSyncTransport;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sprint 4 item 5 — Retry dengan backoff (§11.3, §11.5).
 */
class RetryBackoffTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private Outbox $outbox;

    private FakeSyncTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->device = Device::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->outbox = new Outbox($this->tenant->id);
        $this->transport = new FakeSyncTransport;
    }

    private function enqueue(string $payload = 'sale'): SyncOutbox
    {
        return $this->outbox->enqueue(
            $this->device->id,
            $payload,
            'entity-'.$payload,
            SyncOperationType::Create,
            ['total_minor' => 15000],
        );
    }

    private function pusher(?FakeSyncTransport $transport = null): PushOutbox
    {
        return new PushOutbox(
            $this->tenant->id,
            $this->device->id,
            $transport ?? $this->transport,
        );
    }

    // ------------------------------------------------------------- sukses

    public function test_pushes_pending_entries_and_marks_synced(): void
    {
        $this->enqueue();
        $this->enqueue();

        $summary = $this->pusher()->handle('token');

        $this->assertSame(2, $summary->sent);
        $this->assertSame(2, $summary->synced);
        $this->assertSame(0, $summary->retrying);
        $this->assertSame(0, $this->outbox->pendingCount($this->device->id));
        $this->assertSame(2, SyncOutbox::query()->where('sync_status', SyncStatus::Synced->value)->count());
        $this->assertSame(2, $this->transport->lastBatchSize());
    }

    public function test_duplicate_server_result_counts_as_synced(): void
    {
        $entry = $this->enqueue();
        $this->transport->setStatus($entry->operation_id, 'duplicate');

        $summary = $this->pusher()->handle('token');

        $this->assertSame(1, $summary->synced);
        $this->assertSame(SyncStatus::Synced, $entry->refresh()->sync_status);
    }

    // -------------------------------------------------- hasil tidak diketahui

    public function test_transport_failure_keeps_entries_retryable_and_retains_data(): void
    {
        $entry = $this->enqueue();
        $this->transport->failNext(new SyncTransportException('Koneksi timeout.'));

        $summary = $this->pusher()->handle('token');

        $entry->refresh();

        $this->assertSame(1, $summary->retrying);
        $this->assertSame(0, $summary->synced);
        $this->assertSame(SyncStatus::Pending, $entry->sync_status);
        $this->assertSame(1, $entry->retry_count);
        $this->assertStringContainsString('timeout', (string) $entry->last_error);
        $this->assertSame(['total_minor' => 15000], $entry->payload);
        $this->assertTrue($entry->isRetryable());
    }

    public function test_backoff_delays_the_next_attempt(): void
    {
        $this->travelTo(now()->startOfSecond());

        $entry = $this->enqueue();
        $this->transport->failNext(new SyncTransportException('timeout'));
        $this->pusher()->handle('token');

        // retry_count = 1 → jeda backoff 1 detik: belum siap sekarang.
        $this->assertSame(0, $this->outbox->readyForAttempt($this->device->id)->count());

        $this->travel(1)->seconds();
        $this->assertSame(1, $this->outbox->readyForAttempt($this->device->id)->count());

        $this->assertSame(SyncStatus::Pending, $entry->refresh()->sync_status);
    }

    public function test_exhausted_retries_are_not_retried(): void
    {
        config(['pos.sync.max_retries' => 1]);

        $entry = $this->enqueue();
        $this->transport->failNext(new SyncTransportException('timeout'));

        $this->pusher()->handle('token');

        $entry->refresh();

        $this->assertSame(1, $entry->retry_count);
        $this->assertFalse($entry->isRetryable());
        $this->assertSame(0, $this->outbox->readyForAttempt($this->device->id)->count());
    }

    public function test_resend_after_timeout_is_safe(): void
    {
        $entry = $this->enqueue();
        $this->transport->failNext(new SyncTransportException('timeout'));

        $this->pusher()->handle('token');
        $this->assertSame(SyncStatus::Pending, $entry->refresh()->sync_status);

        $this->travel(2)->seconds();

        $summary = $this->pusher()->handle('token');

        $entry->refresh();

        $this->assertSame(1, $summary->synced);
        $this->assertSame(SyncStatus::Synced, $entry->sync_status);
        $this->assertSame(1, $entry->retry_count);
        $this->assertSame(2, $this->transport->callCount());
    }

    // ------------------------------------------------- penolakan & konflik

    public function test_server_rejection_marks_failed(): void
    {
        $entry = $this->enqueue();
        $this->transport->setStatus($entry->operation_id, 'rejected', 'Stok tidak valid.');

        $summary = $this->pusher()->handle('token');

        $entry->refresh();

        $this->assertSame(1, $summary->failed);
        $this->assertSame(SyncStatus::Failed, $entry->sync_status);
        $this->assertSame('Stok tidak valid.', $entry->last_error);
    }

    public function test_conflict_result_marks_conflict(): void
    {
        $entry = $this->enqueue();
        $this->transport->setStatus($entry->operation_id, 'conflict', 'Stok berubah.');

        $summary = $this->pusher()->handle('token');

        $this->assertSame(1, $summary->conflicted);
        $this->assertSame(SyncStatus::Conflict, $entry->refresh()->sync_status);
    }

    public function test_unknown_status_is_retried(): void
    {
        $entry = $this->enqueue();
        $this->transport->setStatus($entry->operation_id, 'whatever');

        $summary = $this->pusher()->handle('token');

        $this->assertSame(1, $summary->retrying);
        $this->assertSame(SyncStatus::Pending, $entry->refresh()->sync_status);
    }

    // --------------------------------------------------------------- batch

    public function test_batch_size_limits_entries_per_round(): void
    {
        config(['pos.offline.batch_size' => 2]);

        $this->enqueue();
        $this->enqueue();
        $this->enqueue();

        $summary = $this->pusher()->handle('token');

        $this->assertSame(2, $summary->sent);
        $this->assertSame(2, $this->transport->lastBatchSize());
        $this->assertSame(1, $this->outbox->pendingCount($this->device->id));
    }

    // -------------------------------------------------------- crash recovery

    public function test_stale_in_flight_entry_is_reclaimed(): void
    {
        $entry = $this->enqueue();

        // Simulasi proses mati setelah menandai in_flight (§13).
        SyncOutbox::query()->where('operation_id', $entry->operation_id)->update([
            'sync_status' => SyncStatus::InFlight->value,
            'updated_at' => now()->subSeconds(600),
        ]);

        $summary = $this->pusher()->handle('token');

        $this->assertSame(1, $summary->synced);
        $this->assertSame(SyncStatus::Synced, $entry->refresh()->sync_status);
    }

    public function test_recent_in_flight_entry_is_left_alone(): void
    {
        $entry = $this->enqueue();

        SyncOutbox::query()->where('operation_id', $entry->operation_id)->update([
            'sync_status' => SyncStatus::InFlight->value,
            'updated_at' => now(),
        ]);

        $summary = $this->pusher()->handle('token');

        $this->assertSame(0, $summary->sent);
        $this->assertSame(0, $this->transport->callCount());
        $this->assertSame(SyncStatus::InFlight, $entry->refresh()->sync_status);
    }

    // -------------------------------------------------- transport HTTP nyata

    public function test_http_transport_posts_batch_to_sync_push_endpoint(): void
    {
        config(['pos.sync.server_url' => 'https://server.test']);
        Http::fake([
            '*/api/v1/sync/push' => Http::response([
                'results' => [['operation_id' => 'op-1', 'status' => 'received']],
            ]),
        ]);

        $results = (new HttpSyncTransport)->push('tok-123', [
            ['operation_id' => 'op-1', 'entity_type' => 'sale', 'entity_id' => 'e-1',
                'operation_type' => 'create', 'payload' => [], 'local_sequence' => 1],
        ]);

        $this->assertSame('received', $results[0]['status']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://server.test/api/v1/sync/push'
                && $request->hasHeader('Authorization', 'Bearer tok-123')
                && $request['operations'][0]['operation_id'] === 'op-1';
        });
    }

    public function test_http_transport_raises_on_server_error(): void
    {
        config(['pos.sync.server_url' => 'https://server.test']);
        Http::fake(['*/api/v1/sync/push' => Http::response('boom', 500)]);

        $this->expectException(SyncTransportException::class);

        (new HttpSyncTransport)->push('tok', [
            ['operation_id' => 'op-1'],
        ]);
    }
}
