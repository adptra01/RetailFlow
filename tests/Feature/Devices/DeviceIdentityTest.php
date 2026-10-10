<?php

namespace Tests\Feature\Devices;

use App\Actions\Devices\RegisterDevice;
use App\Actions\Devices\RevokeDevice;
use App\Audit\AuditRecorder;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Devices\DeviceNotFound;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Devices\DeviceContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeviceIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private RegisterDevice $register;

    private RevokeDevice $revoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->register = new RegisterDevice($this->tenant->id);
        $this->revoke = new RevokeDevice($this->tenant->id);
    }

    public function test_register_issues_token_and_stores_only_its_hash(): void
    {
        $registration = $this->register->handle($this->owner, 'Kasir 1', 'windows', appVersion: '1.2.0');

        $device = $registration->device;

        $this->assertStringStartsWith('rf_', $registration->token);
        $this->assertSame(51, strlen($registration->token));
        $this->assertSame(hash('sha256', $registration->token), $device->token_hash);
        $this->assertSame(substr($registration->token, 0, 12), $device->token_prefix);
        $this->assertSame($this->tenant->id, $device->tenant_id);
        $this->assertSame('windows', $device->platform);
        $this->assertNotNull($device->last_seen_at);
        $this->assertFalse($device->isRevoked());

        // Plaintext token tidak pernah tersimpan & tidak muncul di serialisasi.
        $this->assertArrayNotHasKey('token_hash', $device->toArray());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'device.registered',
            'entity_type' => 'device',
            'entity_id' => $device->id,
            'actor_id' => $this->owner->id,
        ]);
    }

    public function test_find_by_token_identifies_active_device(): void
    {
        $registration = $this->register->handle($this->owner, 'Kasir 1', 'android');

        $found = Device::findByToken($registration->token);

        $this->assertNotNull($found);
        $this->assertSame($registration->device->id, $found->id);
    }

    public function test_wrong_token_returns_null(): void
    {
        $this->register->handle($this->owner, 'Kasir 1', 'windows');

        $this->assertNull(Device::findByToken('rf_token-palsu'));
    }

    public function test_revoked_device_is_rejected_and_revocation_is_idempotent(): void
    {
        $registration = $this->register->handle($this->owner, 'Kasir 1', 'windows');
        $device = $registration->device;
        $token = $registration->token;

        $this->revoke->handle($this->owner, $device->id, 'Perangkat hilang');

        $this->assertNotNull($device->refresh()->revoked_at);
        $this->assertNull(Device::findByToken($token));

        // Idempoten: pencabutan ulang tidak menambah jejak audit.
        $this->revoke->handle($this->owner, $device->id);

        $this->assertSame(1, AuditLog::query()->where('action', 'device.revoked')->count());
    }

    public function test_register_and_revoke_require_permission_when_actor_given(): void
    {
        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashier->assignRole('cashier');

        try {
            $this->register->handle($cashier, 'Kasir 1', 'windows');
            $this->fail('Pendaftaran seharusnya memerlukan izin user.manage.');
        } catch (ActionForbidden) {
            // diharapkan
        }

        $registration = $this->register->handle($this->owner, 'Kasir 1', 'windows');

        $this->expectException(ActionForbidden::class);
        $this->revoke->handle($cashier, $registration->device->id);
    }

    public function test_register_without_actor_is_allowed_for_onboarding(): void
    {
        $registration = $this->register->handle(null, 'Kasir Onboarding', 'windows');

        $this->assertDatabaseHas('devices', ['id' => $registration->device->id]);
        $this->assertNull(
            AuditLog::query()->where('action', 'device.registered')->first()?->actor_id
        );
    }

    public function test_revoke_unknown_device_throws_not_found(): void
    {
        $this->expectException(DeviceNotFound::class);

        $this->revoke->handle($this->owner, (string) Str::uuid());
    }

    public function test_revoke_of_other_tenant_device_throws_not_found(): void
    {
        $other = Device::factory()->create();
        $action = new RevokeDevice($this->tenant->id);

        $this->expectException(DeviceNotFound::class);

        $action->handle($this->owner, $other->id);
    }

    public function test_audit_recorder_uses_device_context(): void
    {
        $device = Device::factory()->create(['tenant_id' => $this->tenant->id]);
        app(DeviceContext::class)->set($device);

        $log = (new AuditRecorder($this->tenant->id))->record('device.test', $this->owner);

        $this->assertSame($device->id, $log->device_id);
    }

    public function test_mark_seen_updates_last_seen_and_version(): void
    {
        $device = Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'last_seen_at' => now()->subDay(),
            'app_version' => '1.0.0',
        ]);

        $device->markSeen('2.0.0');

        $this->assertSame('2.0.0', $device->refresh()->app_version);
        $this->assertTrue($device->last_seen_at->isAfter(now()->subMinute()));
    }
}
