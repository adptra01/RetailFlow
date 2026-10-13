<?php

namespace Tests\Feature\Api;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `POST /api/v1/auth/login` (§18, §15) — Increment 52.
 *
 * Login menukar kredensial pengguna dengan token API (Sanctum) yang membawa
 * izin FR-12 sebagai ability. Identitas perangkat TIDAK dibuat di sini
 * (itu tugas `devices/register`), dan token pengguna tidak berlaku sebagai
 * token perangkat.
 */
class ApiLoginTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function login(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', array_merge([
            'email' => $this->owner->email,
            'password' => 'password',
            'device_name' => 'Kasir 1',
            'platform' => 'windows',
        ], $overrides));
    }

    public function test_login_returns_user_token_with_permissions_and_audit(): void
    {
        $response = $this->login()->assertOk();

        $response->assertJsonPath('token_type', 'Bearer');
        $response->assertJsonPath('user.email', $this->owner->email);
        $response->assertJsonPath('tenant.id', $this->tenant->id);
        $response->assertJsonPath('roles', ['owner']);

        $token = (string) $response->json('token');
        $this->assertStringContainsString('|', $token);

        $permissions = $response->json('permissions');
        $this->assertContains(Permission::ManageUsers->value, $permissions);
        $this->assertContains(Permission::VoidTransaction->value, $permissions);

        // Token punya masa berlaku (NFR-03), default 30 hari.
        $expiresAt = Carbon::parse($response->json('expires_at'));
        $this->assertTrue(
            $expiresAt->between(now()->addDays(29), now()->addDays(31)),
            'Token harus kedaluwarsa sekitar 30 hari.',
        );

        // Hanya hash yang tersimpan, kemampuan tercatat pada token.
        $stored = PersonalAccessToken::query()->sole();
        $this->assertSame('Kasir 1', $stored->name);
        $this->assertNotSame($token, $stored->token);
        $this->assertContains(Permission::ManageUsers->value, $stored->abilities);
        $this->assertNotNull($stored->expires_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'api.login',
            'actor_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_wrong_password_is_rejected_without_leaking_account_state(): void
    {
        $this->login(['password' => 'kata-sandi-salah'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Email atau kata sandi salah.');

        // Email tak dikenal dijawab pesan yang sama: tidak ada petunjuk akun.
        $this->login(['email' => 'belum-terdaftar@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Email atau kata sandi salah.');

        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'api.login']);
    }

    public function test_inactive_account_is_rejected(): void
    {
        $this->owner->update(['is_active' => false]);

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun pengguna nonaktif — hubungi pemilik toko.');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_user_without_tenant_is_rejected(): void
    {
        app(TenantContext::class)->set(null);
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('owner');

        $this->login(['email' => $user->email])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Pengguna belum terhubung ke toko mana pun.');
    }

    public function test_device_identity_is_required(): void
    {
        $this->login(['device_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_name');

        $this->login(['platform' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < (int) config('pos.api.login_attempts_per_minute'); $attempt++) {
            $this->login()->assertOk();
        }

        $this->login()->assertStatus(429);
    }

    public function test_relogin_on_the_same_device_revokes_the_previous_token(): void
    {
        $first = (string) $this->login()->json('token');
        $second = (string) $this->login()->json('token');

        $this->assertSame(1, PersonalAccessToken::count());

        $payload = ['name' => 'Kasir 1', 'platform' => 'windows'];

        $this->postJson('/api/v1/devices/register', $payload, ['Authorization' => 'Bearer '.$first])
            ->assertStatus(401);

        $this->postJson('/api/v1/devices/register', $payload, ['Authorization' => 'Bearer '.$second])
            ->assertStatus(201);
    }

    public function test_token_expiry_can_be_configured(): void
    {
        config(['pos.api.token_days' => 0]);

        $response = $this->login()->assertOk();

        $this->assertNull($response->json('expires_at'));
        $this->assertNull(PersonalAccessToken::query()->sole()->expires_at);
    }

    public function test_a_user_token_is_not_a_device_token(): void
    {
        $token = (string) $this->login()->json('token');

        // Endpoint sinkronisasi hanya menerima token perangkat (§12, §15).
        $this->getJson('/api/v1/sync/pull', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Perangkat tidak dikenal atau telah dicabut.');
    }
}
