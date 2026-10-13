<?php

namespace Tests\Feature\Api;

use App\Enums\Module;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `GET /api/v1/bootstrap` (§18) — Increment 53.
 *
 * Satu panggilan penyiapan klien: identitas toko & pengguna, modul efektif
 * (FR-13), metode pembayaran (FR-05), lokasi aktif (§12), dan kebijakan
 * domain yang memengaruhi perilaku kasir.
 */
class BootstrapApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create([
            'feature_profile' => 'retail',
        ]);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        app(TenantContext::class)->set($this->tenant);
        $this->token = $this->owner->createToken('Kasir 1')->plainTextToken;
    }

    private function bootstrap(): TestResponse
    {
        return $this->getJson('/api/v1/bootstrap', ['Authorization' => 'Bearer '.$this->token]);
    }

    public function test_bootstrap_returns_tenant_user_modules_and_policies(): void
    {
        $response = $this->bootstrap()->assertOk();

        $response->assertJsonPath('tenant.id', $this->tenant->id);
        $response->assertJsonPath('tenant.currency_code', $this->tenant->currency_code);
        $response->assertJsonPath('user.id', $this->owner->getKey());
        $response->assertJsonPath('user.roles', ['owner']);
        $response->assertJsonPath('currency.code', 'IDR');
        $response->assertJsonPath('currency.minor_exponent', 0);

        $modules = $response->json('modules');
        $this->assertContains(Module::Products->value, $modules);
        $this->assertContains(Module::Cashier->value, $modules);

        $permissions = $response->json('user.permissions');
        $this->assertContains('access.module', $permissions);

        // Kebijakan domain mengikuti konfigurasi, bukan tebakan klien.
        $response->assertJsonPath(
            'policies.receivables.default_due_days',
            (int) config('pos.receivables.default_due_days'),
        );
        $response->assertJsonPath(
            'policies.receivables.enforce_credit_limit',
            (bool) config('pos.receivables.enforce_credit_limit'),
        );
        $response->assertJsonPath(
            'policies.sync.max_batch',
            (int) config('pos.sync.max_batch'),
        );

        $this->assertNotNull($response->json('server_time'));

        // Metode pembayaran: `mixed` bukan pilihan kasir (FR-05).
        $methods = $response->json('payment_methods');
        $this->assertContains('cash', array_column($methods, 'value'));
        $this->assertNotContains('mixed', array_column($methods, 'value'));
    }

    public function test_bootstrap_lists_only_active_locations_of_the_tenant(): void
    {
        $active = Location::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Utama',
            'is_active' => true,
        ]);

        Location::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Tutup',
            'is_active' => false,
        ]);

        Location::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'name' => 'Cabang Tetangga',
            'is_active' => true,
        ]);

        $locations = $this->bootstrap()->assertOk()->json('locations');

        $this->assertSame([$active->id], array_column($locations, 'id'));
    }

    public function test_modules_follow_the_tenants_explicit_override(): void
    {
        $this->tenant->update(['enabled_modules' => [Module::Cashier->value]]);

        $modules = $this->bootstrap()->assertOk()->json('modules');

        $this->assertSame([Module::Cashier->value], $modules);
    }

    public function test_bootstrap_requires_authentication(): void
    {
        $this->getJson('/api/v1/bootstrap')->assertStatus(401);
    }

    public function test_bootstrap_requires_module_access_permission(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $token = $user->createToken('Kasir 2')->plainTextToken;

        $this->getJson('/api/v1/bootstrap', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Aksi memerlukan izin «Membuka modul» (access.module).');
    }

    public function test_bootstrap_rejects_a_user_without_tenant(): void
    {
        app(TenantContext::class)->set(null);

        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('owner');
        $token = $user->createToken('Kasir 3')->plainTextToken;

        $this->getJson('/api/v1/bootstrap', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Pengguna belum terhubung ke toko mana pun.');
    }
}
