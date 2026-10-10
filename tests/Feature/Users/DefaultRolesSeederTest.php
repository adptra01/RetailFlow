<?php

namespace Tests\Feature\Users;

use App\Enums\Permission;
use App\Enums\Role;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionRecord;
use Spatie\Permission\Models\Role as RoleRecord;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DefaultRolesSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultRolesSeeder::class);
    }

    public function test_seeds_all_roles_and_permissions(): void
    {
        $this->assertSame(6, RoleRecord::count());
        $this->assertSame(9, PermissionRecord::count());

        foreach (Role::cases() as $role) {
            $this->assertNotNull(RoleRecord::findByName($role->value));
        }
        foreach (Permission::cases() as $permission) {
            $this->assertNotNull(PermissionRecord::findByName($permission->value));
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DefaultRolesSeeder::class);
        $this->seed(DefaultRolesSeeder::class);

        $this->assertSame(6, RoleRecord::count());
        $this->assertSame(9, PermissionRecord::count());
    }

    public function test_each_role_gets_exact_matrix_permissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            $record = RoleRecord::findByName($role->value);

            $this->assertSame(
                array_map(fn (Permission $p) => $p->value, $role->permissions()),
                $record->permissions->pluck('name')->all(),
                "Matriks izin role '{$role->value}' tidak cocok.",
            );
        }
    }

    public function test_cashier_role_enables_discount_but_not_user_management(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cashier = RoleRecord::findByName(Role::Cashier->value);

        $this->assertTrue($cashier->hasPermissionTo(Permission::ApplyDiscount->value));
        $this->assertFalse($cashier->hasPermissionTo(Permission::ManageUsers->value));
    }

    public function test_legacy_roles_are_touched_only_by_their_own_seeder(): void
    {
        // DefaultRolesSeeder bersifat additive: peran legacy ('pemilik') dari
        // RolePermissionSeeder tidak ikut dibuat/dihapus di sini.
        $this->assertNull(RoleRecord::query()->where('name', 'pemilik')->first());
    }
}
