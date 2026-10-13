<?php

namespace Tests\Feature\Api;

use App\Enums\Permission;
use App\Models\Device;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `POST /api/v1/devices/register` (§18, §12, §15) — Increment 52.
 *
 * Pengguna terautentikasi mendaftarkan perangkat dan menerima token
 * perangkat SEKALI; token itulah yang dipakai endpoint sinkronisasi.
 * Tenant selalu diambil dari pengguna, bukan dari body permintaan (§12).
 */
class DeviceRegisterApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');
    }

    private function userToken(User $user, string $deviceName = 'Kasir 1'): string
    {
        $token = $user->createToken($deviceName, [$user->getAllPermissions()->pluck('name')->first() ?? '']);

        return $token->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function register(array $payload = [], ?string $token = null): TestResponse
    {
        return $this->postJson(
            '/api/v1/devices/register',
            array_merge(['name' => 'Kasir 1', 'platform' => 'windows'], $payload),
            ['Authorization' => 'Bearer '.($token ?? $this->userToken($this->owner))],
        );
    }

    public function test_owner_registers_a_device_and_receives_a_working_device_token(): void
    {
        $response = $this->register(['app_version' => '1.0.0'])->assertStatus(201);

        $token = (string) $response->json('token');
        $this->assertStringStartsWith('rf_', $token);
        $response->assertJsonPath('token_type', 'Device');
        $response->assertJsonPath('device.name', 'Kasir 1');
        $response->assertJsonPath('device.platform', 'windows');

        $device = Device::query()->sole();
        $this->assertSame($this->tenant->id, $device->tenant_id);
        $this->assertSame(substr($token, 0, 12), $response->json('device.token_prefix'));
        $this->assertArrayNotHasKey('token_hash', $device->toArray());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'device.registered',
            'entity_type' => 'device',
            'entity_id' => $device->id,
            'actor_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
        ]);

        // Token perangkat langsung dapat dipakai endpoint sinkronisasi.
        $this->getJson('/api/v1/sync/pull', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('device_id', $device->id);
    }

    public function test_the_users_own_location_is_accepted(): void
    {
        $location = Location::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->register(['location_id' => $location->id])->assertStatus(201);

        $this->assertSame($location->id, Device::query()->sole()->location_id);
    }

    public function test_a_location_from_another_tenant_is_rejected(): void
    {
        $foreign = Location::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->register(['location_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');

        $this->assertSame(0, Device::count());
    }

    public function test_a_user_without_permission_cannot_register_a_device(): void
    {
        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashier->assignRole('cashier');

        $this->assertFalse($cashier->can(Permission::ManageUsers->value));

        $this->register(token: $this->userToken($cashier, 'Kasir 2'))
            ->assertStatus(403);

        $this->assertSame(0, Device::count());
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/devices/register', ['name' => 'Kasir 1', 'platform' => 'windows'])
            ->assertStatus(401);

        $this->assertSame(0, Device::count());
    }

    public function test_an_inactive_user_cannot_register_a_device(): void
    {
        $token = $this->userToken($this->owner);

        $this->owner->update(['is_active' => false]);

        $this->register(token: $token)->assertStatus(403);

        $this->assertSame(0, Device::count());
    }

    public function test_a_revoked_user_token_is_rejected(): void
    {
        $token = $this->userToken($this->owner);

        PersonalAccessToken::query()->delete();

        $this->register(token: $token)->assertStatus(401);

        $this->assertSame(0, Device::count());
    }
}
