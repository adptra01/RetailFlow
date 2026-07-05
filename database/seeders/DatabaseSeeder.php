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
            BrandSeeder::class,
            SupplierSeeder::class,
            CustomerSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            TransactionSeeder::class,
            PurchaseOrderSeeder::class,
            StockMovementSeeder::class,
            StockOpnameSeeder::class,
            ReturnSeeder::class,
            PaymentSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
