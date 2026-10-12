<?php

namespace Tests\Feature\Users;

use App\Enums\Permission;
use App\Enums\Role;
use Tests\TestCase;

class RolePermissionCatalogTest extends TestCase
{
    public function test_permission_catalog_matches_fr12_capabilities(): void
    {
        $this->assertCount(10, Permission::cases());

        $this->assertSame([
            'access.module',
            'price.update',
            'discount.apply',
            'transaction.void',
            'transaction.return',
            'stock.adjust',
            'report.financial.view',
            'user.manage',
            'payment_gateway.configure',
            'backup.restore',
        ], array_map(fn (Permission $p) => $p->value, Permission::cases()));
    }

    public function test_permission_labels_are_defined(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertNotEmpty($permission->label());
        }
    }

    public function test_role_catalog_matches_fr12_initial_roles(): void
    {
        $this->assertCount(6, Role::cases());

        $this->assertSame([
            'owner',
            'admin',
            'supervisor',
            'cashier',
            'inventory_manager',
            'viewer',
        ], array_map(fn (Role $role) => $role->value, Role::cases()));
    }

    public function test_owner_has_every_permission(): void
    {
        $this->assertSame(Permission::cases(), Role::Owner->permissions());
        $this->assertTrue(Role::Owner->isOwner());
    }

    public function test_admin_has_everything_except_restore(): void
    {
        $expected = array_values(array_filter(
            Permission::cases(),
            fn (Permission $p) => $p !== Permission::RestoreBackup,
        ));

        $this->assertSame($expected, Role::Admin->permissions());
        $this->assertNotContains(Permission::RestoreBackup, Role::Admin->permissions());
    }

    public function test_supervisor_matrix(): void
    {
        $this->assertSame([
            Permission::AccessModule,
            Permission::ApplyDiscount,
            Permission::VoidTransaction,
            Permission::ReturnTransaction,
            Permission::AdjustStock,
            Permission::ViewFinancialReports,
        ], Role::Supervisor->permissions());

        $this->assertNotContains(Permission::UpdatePrice, Role::Supervisor->permissions());
        $this->assertNotContains(Permission::ManageUsers, Role::Supervisor->permissions());
    }

    public function test_cashier_matrix(): void
    {
        $this->assertSame([
            Permission::AccessModule,
            Permission::ApplyDiscount,
            Permission::VoidTransaction,
            Permission::ReturnTransaction,
        ], Role::Cashier->permissions());

        $this->assertNotContains(Permission::AdjustStock, Role::Cashier->permissions());
        $this->assertNotContains(Permission::ViewFinancialReports, Role::Cashier->permissions());
    }

    public function test_inventory_manager_matrix(): void
    {
        $this->assertSame([
            Permission::AccessModule,
            Permission::AdjustStock,
        ], Role::InventoryManager->permissions());

        $this->assertNotContains(Permission::UpdatePrice, Role::InventoryManager->permissions());
        $this->assertNotContains(Permission::ViewFinancialReports, Role::InventoryManager->permissions());
    }

    public function test_viewer_matrix(): void
    {
        $this->assertSame([
            Permission::AccessModule,
            Permission::ViewFinancialReports,
        ], Role::Viewer->permissions());

        $this->assertNotContains(Permission::ApplyDiscount, Role::Viewer->permissions());
        $this->assertFalse(Role::Viewer->isOwner());
    }

    public function test_role_labels_are_defined(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertNotEmpty($role->label());
        }
    }
}
