<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionsByGroup = [
            'users' => ['view', 'create', 'edit', 'delete'],
            'roles' => ['view', 'create', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'products' => ['view', 'create', 'edit', 'delete'],
            'categories' => ['view', 'create', 'edit', 'delete'],
            'brands' => ['view', 'create', 'edit', 'delete'],
            'suppliers' => ['view', 'create', 'edit', 'delete'],
            'customers' => ['view', 'create', 'edit', 'delete'],
            'transactions' => ['view', 'create', 'edit', 'delete'],
            'purchases' => ['view', 'create', 'edit', 'delete'],
            'stock' => ['view'],
            'opname' => ['view', 'create'],
            'reports' => ['view'],
            'settings' => ['store', 'profile', 'security'],
        ];

        $permissions = collect();

        foreach ($permissionsByGroup as $group => $actions) {
            foreach ($actions as $action) {
                $name = "{$group}.{$action}";
                $permissions->push($name);
                Permission::findOrCreate($name);
            }
        }

        $admin = Role::findOrCreate('admin');
        $admin->syncPermissions($permissions);

        $pemilik = Role::findOrCreate('pemilik');
        $pemilik->syncPermissions(
            $permissions->filter(fn ($p) =>
                str($p)->startsWith('products.')
                || str($p)->startsWith('categories.')
                || str($p)->startsWith('brands.')
                || str($p)->startsWith('suppliers.')
                || str($p)->startsWith('customers.')
                || str($p)->startsWith('purchases.')
                || str($p)->startsWith('stock.')
                || str($p)->startsWith('opname.')
                || $p === 'transactions.view'
                || $p === 'transactions.create'
                || $p === 'reports.view'
            )
        );

        $adminUser = User::where('email', 'admin@testing.com')->first();
        $pemilikUser = User::where('email', 'pemilik@testing.com')->first();

        if ($adminUser) {
            $adminUser->assignRole('admin');
        }

        if ($pemilikUser) {
            $pemilikUser->assignRole('pemilik');
        }
    }
}
