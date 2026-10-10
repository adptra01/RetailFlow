<?php

namespace Tests\Feature\Sync;

use App\Actions\Sync\SyncNow;
use App\Enums\SyncOperationType;
use App\Enums\SyncStatus;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\SyncStatusReport;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 4 item 8 — Status sinkronisasi UI (§11.5).
 */
class SyncStatusUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->device = Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_name' => 'Kasir 1',
        ]);
    }

    private function enqueue(SyncStatus $status = SyncStatus::Pending): SyncOutbox
    {
        $entry = (new Outbox($this->tenant->id))->enqueue(
            $this->device->id,
            'sale',
            (string) Str::uuid(),
            SyncOperationType::Create,
            ['total_minor' => 1000],
        );

        match ($status) {
            SyncStatus::Pending => null,
            SyncStatus::InFlight => $entry->markInFlight(),
            SyncStatus::Synced => $entry->markSynced(),
            SyncStatus::Failed => $entry->markFailed('Ditolak server.'),
            SyncStatus::Conflict => $entry->markConflict('Konflik stok.'),
        };

        return $entry->refresh();
    }

    // ---------------------------------------------------------------- ringkasan

    public function test_report_state_is_unknown_when_nothing_happened(): void
    {
        $summary = (new SyncStatusReport($this->tenant->id, $this->device->id))->summary();

        $this->assertSame('unknown', $summary['state']);
        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['pending_user_visible']);
        $this->assertNull($summary['last_synced_at']);
    }

    public function test_report_state_is_pending_with_queued_operations(): void
    {
        $this->enqueue();
        $this->enqueue(SyncStatus::InFlight);

        $summary = (new SyncStatusReport($this->tenant->id, $this->device->id))->summary();

        $this->assertSame('pending', $summary['state']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(1, $summary['in_flight']);
        $this->assertSame(2, $summary['pending_user_visible']);
        $this->assertNotNull($summary['oldest_pending_at']);
    }

    public function test_report_state_is_synced_after_a_successful_round(): void
    {
        $this->enqueue(SyncStatus::Synced);

        $summary = (new SyncStatusReport($this->tenant->id, $this->device->id))->summary();

        $this->assertSame('synced', $summary['state']);
        $this->assertSame(1, $summary['synced']);
        $this->assertSame(0, $summary['pending_user_visible']);
        $this->assertNotNull($summary['last_synced_at']);
    }

    public function test_report_state_is_attention_with_failures_or_conflicts(): void
    {
        $this->enqueue(SyncStatus::Failed);

        $summary = (new SyncStatusReport($this->tenant->id, $this->device->id))->summary();

        $this->assertSame('attention', $summary['state']);
        $this->assertSame(1, $summary['failed']);
    }

    public function test_report_attention_with_open_conflict_case(): void
    {
        SyncConflict::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->device->id,
            'operation_id' => (string) Str::uuid(),
            'type' => 'stock',
            'status' => 'open',
            'details' => ['reason' => 'insufficient_stock'],
        ]);

        $summary = (new SyncStatusReport($this->tenant->id, $this->device->id))->summary();

        $this->assertSame('attention', $summary['state']);
        $this->assertSame(1, $summary['open_conflicts']);
        $this->assertCount(1, (new SyncStatusReport($this->tenant->id, $this->device->id))->openConflicts());
    }

    public function test_unsynced_list_excludes_synced_operations(): void
    {
        $this->enqueue(SyncStatus::Synced);
        $pending = $this->enqueue();

        $report = new SyncStatusReport($this->tenant->id, $this->device->id);
        $unsynced = $report->unsyncedOperations();

        $this->assertCount(1, $unsynced);
        $this->assertSame($pending->operation_id, $unsynced[0]['operation_id']);
        $this->assertSame('pending', $unsynced[0]['sync_status']);
    }

    // ---------------------------------------------------------------- sinkronkan

    public function test_sync_now_requires_a_device_token(): void
    {
        config()->set('pos.sync.device_token', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Token sinkronisasi/');

        (new SyncNow($this->tenant->id, $this->device->id, new FakeSyncTransport))->handle();
    }

    public function test_sync_now_rejects_revoked_device(): void
    {
        config()->set('pos.sync.device_token', 'rf_token');
        $this->device->update(['revoked_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dicabut/');

        (new SyncNow($this->tenant->id, $this->device->id, new FakeSyncTransport))->handle();
    }

    public function test_sync_now_pushes_pending_operations_and_updates_heartbeat(): void
    {
        config()->set('pos.sync.device_token', 'rf_token');

        $first = $this->enqueue();
        $second = $this->enqueue();

        $transport = (new FakeSyncTransport)
            ->setStatus($first->operation_id, 'applied')
            ->setStatus($second->operation_id, 'received');

        $summary = (new SyncNow($this->tenant->id, $this->device->id, $transport))->handle();

        $this->assertSame(2, $summary->sent);
        $this->assertSame(2, $summary->synced);
        $this->assertSame(0, $summary->failed);

        $this->assertSame(SyncStatus::Synced, $first->refresh()->sync_status);
        $this->assertSame(SyncStatus::Synced, $second->refresh()->sync_status);
        $this->assertNotNull($this->device->refresh()->last_seen_at);
    }

    // -------------------------------------------------------------------- halaman

    public function test_guests_are_redirected_from_the_sync_page(): void
    {
        $this->get(route('sync.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_the_sync_page(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($user)
            ->get(route('sync.index'))
            ->assertOk()
            ->assertSee('Status Sinkronisasi');
    }
}
