<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role as PermissionRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seed peran & izin awal FR-12 — idempoten, aman dijalankan berulang.
 *
 * Matriks izin berasal dari `Role::permissions()` (sumber tunggal), bukan
 * daftar terpisah. Peran lama aplikasi legacy (`pemilik`, dll.) tidak
 * disentuh — additive.
 */
class DefaultRolesSeeder extends Seeder
{
    public function run(): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // Pastikan seluruh izin katalog terdaftar (guard default).
        foreach (Permission::cases() as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission->value);
        }

        foreach (Role::cases() as $role) {
            $record = PermissionRole::findOrCreate($role->value);
            $record->syncPermissions(
                array_map(fn (Permission $p) => $p->value, $role->permissions()),
            );
        }

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
