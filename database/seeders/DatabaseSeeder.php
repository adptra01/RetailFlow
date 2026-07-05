<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
        ]);

        $admin = User::factory()->create([
            'name' => 'Admin Toko',
            'email' => 'admin@testing.com',
        ]);
        $admin->assignRole('admin');

        $pemilik = User::factory()->create([
            'name' => 'Pemilik Toko',
            'email' => 'pemilik@testing.com',
        ]);
        $pemilik->assignRole('pemilik');

        $this->call([
            CategorySeeder::class,
            ProductSeeder::class,
            TransactionSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
