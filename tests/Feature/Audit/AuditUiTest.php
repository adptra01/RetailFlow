<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 3 item 6 — UI jejak audit tindakan sensitif (FR-12).
 *
 * Halaman hanya boleh diakses pemegang izin `user.manage` (Owner/Admin),
 * menampilkan aktor/waktu/perangkat/jenis tindakan/alasan, dan terisolasi
 * per tenant.
 */
class AuditUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole('cashier');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function auditLog(array $attributes = []): AuditLog
    {
        // `created_at` tidak fillable (append-only), jadi diset eksplisit
        // setelah pembuatan agar tes bisa mengatur urutan waktu.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $log = AuditLog::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'actor_id' => $this->owner->id,
            'action' => AuditAction::UserCreated->value,
            'entity_type' => 'user',
            'entity_id' => (string) Str::uuid(),
            'reason' => 'Pembuatan akun kasir baru',
            'metadata' => ['email' => 'kasir@toko.test'],
        ], $attributes));

        if ($createdAt !== null) {
            $log->forceFill(['created_at' => $createdAt])->save();
        }

        return $log;
    }

    public function test_guests_are_redirected_from_the_audit_page(): void
    {
        $this->get(route('audit.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_owner_can_view_the_audit_trail_with_details(): void
    {
        $this->auditLog();

        $this->actingAs($this->owner)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Jejak Audit')
            ->assertSee('Pengguna dibuat')
            ->assertSee('Pembuatan akun kasir baru')
            ->assertSee('kasir@toko.test')
            ->assertSee($this->owner->name);
    }

    public function test_audit_records_of_other_tenants_are_not_visible(): void
    {
        $otherTenant = Tenant::factory()->create();

        $this->auditLog();

        AuditLog::create([
            'tenant_id' => $otherTenant->id,
            'actor_id' => $this->owner->id,
            'action' => AuditAction::BackupRestored->value,
            'reason' => 'Restore rahasia tenant lain',
        ]);

        $this->actingAs($this->owner)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Pengguna dibuat')
            ->assertDontSee('Restore rahasia tenant lain');
    }

    public function test_filter_by_action(): void
    {
        $this->auditLog();

        $this->auditLog([
            'action' => AuditAction::BackupRestored->value,
            'reason' => 'Pulihkan dari snapshot',
        ]);

        $this->actingAs($this->owner)
            ->get(route('audit.index', ['action' => AuditAction::BackupRestored->value]))
            ->assertOk()
            ->assertSee('Backup dipulihkan')
            ->assertSee('Pulihkan dari snapshot')
            ->assertDontSee('Pembuatan akun kasir baru');
    }

    public function test_filter_by_actor(): void
    {
        $this->auditLog();

        AuditLog::create([
            'tenant_id' => $this->tenant->id,
            'actor_id' => $this->cashier->id,
            'action' => AuditAction::SaleCreated->value,
            'reason' => 'Penjualan kasir',
        ]);

        $this->actingAs($this->owner)
            ->get(route('audit.index', ['actor' => (string) $this->cashier->id]))
            ->assertOk()
            ->assertSee('Penjualan kasir')
            ->assertDontSee('Pembuatan akun kasir baru');
    }

    public function test_filter_by_date_range_excludes_out_of_range_records(): void
    {
        $this->auditLog(['created_at' => now()->subDays(10), 'reason' => 'Terlalu lama untuk filter']);
        $this->auditLog([
            'action' => AuditAction::DeviceRevoked->value,
            'reason' => 'Perangkat hilang hari ini',
            'created_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->get(route('audit.index', [
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Perangkat hilang hari ini')
            ->assertDontSee('Terlalu lama untuk filter');
    }

    public function test_records_are_ordered_most_recent_first(): void
    {
        $this->auditLog(['created_at' => now()->subDay(), 'reason' => 'Lebih lama']);
        $this->auditLog(['created_at' => now(), 'reason' => 'Lebih baru']);

        $response = $this->actingAs($this->owner)->get(route('audit.index'));

        $response->assertOk();
        $this->assertLessThan(
            strpos($response->getContent(), 'Lebih lama'),
            strpos($response->getContent(), 'Lebih baru'),
        );
    }

    public function test_user_without_tenant_sees_no_audit_records(): void
    {
        // Fail-closed: `users.tenant_id` nullable, jadi pengguna tanpa tenant
        // tidak boleh melihat jejak audit tenant mana pun.
        $tenantless = User::factory()->create(['tenant_id' => null]);
        $tenantless->givePermissionTo(Permission::ManageUsers->value);

        $this->auditLog(['reason' => 'Rahasia Tenant']);

        $this->actingAs($tenantless->refresh())
            ->get(route('audit.index'))
            ->assertOk()
            ->assertDontSee('Rahasia Tenant');
    }
}
