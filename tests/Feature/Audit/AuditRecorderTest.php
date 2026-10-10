<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_record_writes_all_fr12_fields(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $device = Device::factory()->create(['tenant_id' => $tenant->id]);
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);

        $recorder = new AuditRecorder($tenant->id, $device->id);
        $log = $recorder->record(
            AuditAction::UserDeactivated,
            $actor,
            entityType: 'user',
            entityId: $actor->uuid,
            reason: 'Pindah tugas',
            metadata: ['note' => 'uji'],
        );

        $this->assertNotNull($log->id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame($device->id, $log->device_id);
        $this->assertSame('user.deactivated', $log->action);
        $this->assertSame('user', $log->entity_type);
        $this->assertSame($actor->uuid, $log->entity_id);
        $this->assertSame('Pindah tugas', $log->reason);
        $this->assertSame(['note' => 'uji'], $log->metadata);
        $this->assertNotNull($log->created_at);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'user.deactivated',
            'entity_type' => 'user',
        ]);
    }

    public function test_action_accepts_plain_string(): void
    {
        $log = (new AuditRecorder)->record('custom.action');

        $this->assertSame('custom.action', $log->action);
    }

    public function test_tenant_falls_back_to_actor_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);

        $log = (new AuditRecorder)->record('custom.action', $actor);

        $this->assertSame($tenant->id, $log->tenant_id);
    }

    public function test_tenant_falls_back_to_context(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $log = (new AuditRecorder)->record('custom.action');

        $this->assertSame($tenant->id, $log->tenant_id);
    }

    public function test_explicit_tenant_wins_over_context(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        app(TenantContext::class)->set($tenantB);

        $log = (new AuditRecorder($tenantA->id))->record('custom.action');

        $this->assertSame($tenantA->id, $log->tenant_id);
    }

    public function test_actor_and_tenant_are_optional(): void
    {
        $log = (new AuditRecorder)->record('custom.action');

        $this->assertNull($log->actor_id);
        $this->assertNull($log->tenant_id);
        $this->assertNull($log->device_id);
    }

    public function test_records_are_append_only_without_updated_at(): void
    {
        $log = (new AuditRecorder)->record('custom.action');

        $this->assertNull(AuditLog::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
        $this->assertArrayHasKey('created_at', $log->getAttributes());
    }
}
