<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncOperationType;
use App\Enums\SyncStatus;
use App\Models\Device;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private Outbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->device = Device::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->outbox = new Outbox($this->tenant->id);
    }

    public function test_enqueue_persists_pending_operation_with_sequence(): void
    {
        $entry = $this->outbox->enqueue(
            $this->device->id,
            'sale',
            (string) Str::uuid(),
            SyncOperationType::Create,
            ['total_minor' => 30000],
        );

        $this->assertTrue(Str::isUuid($entry->operation_id));
        $this->assertSame(SyncStatus::Pending, $entry->sync_status);
        $this->assertSame(1, $entry->local_sequence);
        $this->assertSame(0, $entry->retry_count);
        $this->assertSame(['total_minor' => 30000], $entry->payload);
        $this->assertSame($this->tenant->id, $entry->tenant_id);
        $this->assertSame($this->device->id, $entry->device_id);
    }

    public function test_sequence_increments_per_device_independently(): void
    {
        $other = Device::factory()->create(['tenant_id' => $this->tenant->id]);

        $a1 = $this->outbox->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);
        $a2 = $this->outbox->enqueue($this->device->id, 'sale', '2', SyncOperationType::Create);
        $b1 = $this->outbox->enqueue($other->id, 'sale', '3', SyncOperationType::Create);

        $this->assertSame([1, 2], [$a1->local_sequence, $a2->local_sequence]);
        $this->assertSame(1, $b1->local_sequence);
    }

    public function test_outbox_write_is_transactional_with_the_domain_change(): void
    {
        DB::beginTransaction();
        $this->outbox->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);
        DB::rollBack();

        $this->assertSame(0, SyncOutbox::query()->count());

        DB::beginTransaction();
        $this->outbox->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);
        DB::commit();

        $this->assertSame(1, SyncOutbox::query()->count());
    }

    public function test_pending_returns_entries_ordered_by_sequence(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->outbox->enqueue($this->device->id, 'sale', (string) $n, SyncOperationType::Create);
        }

        $pending = $this->outbox->pending($this->device->id);

        $this->assertSame([1, 2, 3], $pending->pluck('local_sequence')->all());
    }

    public function test_mark_in_flight_and_synced_update_status_and_count(): void
    {
        $entry = $this->outbox->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);
        $this->assertSame(1, $this->outbox->pendingCount($this->device->id));

        $entry->markInFlight();
        $this->assertSame(SyncStatus::InFlight, $entry->fresh()->sync_status);

        $entry->markSynced();
        $this->assertSame(SyncStatus::Synced, $entry->fresh()->sync_status);
        $this->assertSame(0, $this->outbox->pendingCount($this->device->id));
        $this->assertSame(0, $this->outbox->pending($this->device->id)->count());
    }

    public function test_mark_failed_increments_retry_and_stops_when_exhausted(): void
    {
        config(['pos.sync.max_retries' => 2]);

        $entry = $this->outbox->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);

        $entry->markFailed('koneksi putus');
        $this->assertSame(SyncStatus::Failed, $entry->fresh()->sync_status);
        $this->assertSame(1, $entry->fresh()->retry_count);
        $this->assertSame('koneksi putus', $entry->fresh()->last_error);
        $this->assertTrue($entry->fresh()->isRetryable());
        $this->assertSame(1, $this->outbox->retryable($this->device->id)->count());

        $entry->markFailed('koneksi putus lagi');
        $this->assertSame(2, $entry->fresh()->retry_count);
        $this->assertFalse($entry->fresh()->isRetryable());
        $this->assertSame(0, $this->outbox->retryable($this->device->id)->count());
    }

    public function test_mark_conflict_records_reason(): void
    {
        $entry = $this->outbox->enqueue($this->device->id, 'stock', '1', SyncOperationType::Update);

        $entry->markConflict('stok tidak cocok');

        $this->assertSame(SyncStatus::Conflict, $entry->fresh()->sync_status);
        $this->assertSame('stok tidak cocok', $entry->fresh()->last_error);
        $this->assertFalse($entry->fresh()->isRetryable());
    }

    public function test_unsynced_count_includes_pending_in_flight_failed_conflict(): void
    {
        $this->outbox->enqueue($this->device->id, 'a', '1', SyncOperationType::Create)->markSynced();
        $this->outbox->enqueue($this->device->id, 'b', '2', SyncOperationType::Create); // pending
        $this->outbox->enqueue($this->device->id, 'c', '3', SyncOperationType::Create)->markInFlight(); // in_flight
        $this->outbox->enqueue($this->device->id, 'd', '4', SyncOperationType::Create)->markFailed('x'); // failed

        // pending + in_flight + failed = 3 (synced tidak dihitung).
        $this->assertSame(3, $this->outbox->unsyncedCount($this->device->id));
    }

    public function test_pending_respects_batch_size(): void
    {
        config(['pos.offline.batch_size' => 2]);

        foreach ([1, 2, 3] as $n) {
            $this->outbox->enqueue($this->device->id, 'sale', (string) $n, SyncOperationType::Create);
        }

        $this->assertSame(2, $this->outbox->pending($this->device->id)->count());
    }

    public function test_enqueue_uses_context_tenant_when_not_explicit(): void
    {
        $outbox = new Outbox;
        $entry = $outbox->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);

        $this->assertSame($this->tenant->id, $entry->tenant_id);
    }

    public function test_enqueue_without_tenant_throws(): void
    {
        app(TenantContext::class)->set(null);

        $this->expectException(\RuntimeException::class);

        (new Outbox)->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);
    }

    public function test_operation_id_is_unique_per_enqueue(): void
    {
        $a = $this->outbox->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);
        $b = $this->outbox->enqueue($this->device->id, 'sale', '1', SyncOperationType::Create);

        $this->assertNotSame($a->operation_id, $b->operation_id);
        $this->assertSame(2, SyncOutbox::query()->distinct()->count('operation_id'));
    }
}
