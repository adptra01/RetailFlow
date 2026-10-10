<?php

namespace Tests\Feature\Users;

use App\Actions\Users\ManageUser;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Users\LastOwnerProtected;
use App\Exceptions\Users\UserNotFound;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManageUserTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ManageUser $manage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole(Role::Owner->value);

        $this->manage = new ManageUser($this->tenant->id);
    }

    /**
     * @return array<string, string>
     */
    private function userInput(string $email = 'kasir@toko.test'): array
    {
        return [
            'name' => 'Kasir Baru',
            'email' => $email,
            'password' => 'rahasia-betul!123',
            'password_confirmation' => 'rahasia-betul!123',
        ];
    }

    public function test_create_user_sets_tenant_role_and_sync_identity(): void
    {
        $created = $this->manage->create($this->owner, $this->userInput(), Role::Cashier);

        $this->assertSame($this->tenant->id, $created->tenant_id);
        $this->assertTrue($created->is_active);
        $this->assertNotNull($created->uuid);
        $this->assertTrue($created->hasRole(Role::Cashier->value));

        // Izin mengikuti peran (FR-12) — terlihat lewat Gate `can()`.
        $this->assertTrue($created->can(Permission::ApplyDiscount->value));
        $this->assertFalse($created->can(Permission::ManageUsers->value));
    }

    public function test_create_user_without_role_has_no_permissions(): void
    {
        $created = $this->manage->create($this->owner, $this->userInput('tanpa-peran@toko.test'));

        $this->assertFalse($created->can(Permission::ApplyDiscount->value));
    }

    public function test_create_user_requires_manage_users_permission(): void
    {
        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashier->assignRole(Role::Cashier->value);

        try {
            $this->manage->create($cashier, $this->userInput());
            $this->fail('Seharusnya dilempar ActionForbidden.');
        } catch (ActionForbidden $e) {
            $this->assertStringContainsString('Mengelola pengguna', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'kasir@toko.test']);
    }

    public function test_create_user_rejects_invalid_input(): void
    {
        $this->expectException(ValidationException::class);

        $this->manage->create($this->owner, [
            'name' => '',
            'email' => 'bukan-email',
            'password' => 'pendek',
            'password_confirmation' => 'beda',
        ], Role::Cashier);
    }

    public function test_deactivate_and_reactivate_user(): void
    {
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $target->assignRole(Role::Cashier->value);

        $this->manage->setActive($this->owner, $target->id, false);
        $this->assertFalse($target->fresh()->is_active);

        $this->manage->setActive($this->owner, $target->id, true);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_last_owner_cannot_be_deactivated(): void
    {
        $this->expectException(LastOwnerProtected::class);

        $this->manage->setActive($this->owner, $this->owner->id, false);
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        try {
            $this->manage->assignRole($this->owner, $this->owner->id, Role::Cashier);
            $this->fail('Seharusnya dilempar LastOwnerProtected.');
        } catch (LastOwnerProtected $e) {
            $this->assertStringContainsString('Owner terakhir', $e->getMessage());
        }

        $this->assertTrue($this->owner->fresh()->hasRole(Role::Owner->value));
    }

    public function test_last_owner_cannot_have_all_roles_removed(): void
    {
        $this->expectException(LastOwnerProtected::class);

        $this->manage->assignRole($this->owner, $this->owner->id, null);
    }

    public function test_deactivating_non_last_owner_is_allowed(): void
    {
        $secondOwner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $secondOwner->assignRole(Role::Owner->value);

        // Masih ada satu owner aktif lain → boleh nonaktifkan owner kedua.
        $this->manage->setActive($this->owner, $secondOwner->id, false);
        $this->assertFalse($secondOwner->fresh()->is_active);

        // Sekarang hanya tersisa satu owner → si owner terakhir tidak bisa.
        $this->expectException(LastOwnerProtected::class);
        $this->manage->setActive($this->owner, $this->owner->id, false);
    }

    public function test_inactive_user_cannot_manage_users(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $admin->assignRole(Role::Admin->value);

        $this->manage->setActive($this->owner, $admin->id, false);

        $this->expectException(ActionForbidden::class);

        $this->manage->create($admin->fresh(), $this->userInput());
    }

    public function test_assign_role_changes_effective_permissions(): void
    {
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $target->assignRole(Role::Cashier->value);

        $this->manage->assignRole($this->owner, $target->id, Role::Viewer);

        $this->assertTrue($target->fresh()->hasRole(Role::Viewer->value));
        $this->assertFalse($target->fresh()->hasRole(Role::Cashier->value));
        $this->assertTrue($target->fresh()->can(Permission::ViewFinancialReports->value));
        $this->assertFalse($target->fresh()->can(Permission::ApplyDiscount->value));
    }

    public function test_update_profile_changes_name_and_email(): void
    {
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $updated = $this->manage->updateProfile($this->owner, $target->id, [
            'name' => 'Nama Ganti',
            'email' => 'ganti@toko.test',
        ]);

        $this->assertSame('Nama Ganti', $updated->name);
        $this->assertSame('ganti@toko.test', $updated->email);
    }

    public function test_update_profile_rejects_duplicate_email(): void
    {
        $existing = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->expectException(ValidationException::class);

        $this->manage->updateProfile($this->owner, $target->id, [
            'name' => 'Duplikat',
            'email' => $existing->email,
        ]);
    }

    public function test_managing_user_of_another_tenant_is_not_found(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignUser->assignRole(Role::Cashier->value);

        $this->expectException(UserNotFound::class);

        $this->manage->setActive($this->owner, $foreignUser->id, false);
    }

    public function test_missing_user_throws_not_found(): void
    {
        $this->expectException(UserNotFound::class);

        $this->manage->setActive($this->owner, 999_999, false);
    }
}
