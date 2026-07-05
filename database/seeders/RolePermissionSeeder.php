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
            'transactions' => ['view', 'create', 'edit', 'delete'],
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
                || $p === 'transactions.view'
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
