<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\SeedsDemoTenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use SeedsDemoTenant;

    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            DefaultRolesSeeder::class,
            RolePermissionSeeder::class,
        ]);

        // §12: tenant default WAJIB terikat sebelum data demo ditulis. Tanpa
        // ikatan ini seluruh baris lahir dengan `tenant_id` NULL dan tidak
        // akan pernah terlihat di aplikasi (setiap permintaan menyaring tenant).
        $this->demoTenant();

        // User demo dibuat idempoten supaya `db:seed` bisa dijalankan ulang
        // (mis. setelah `migrate:fresh` sebagian) tanpa menabrak unik email.
        $admin = User::query()->where('email', 'admin@testing.com')->first()
            ?? User::factory()->create([
                'name' => 'Admin Toko',
                'email' => 'admin@testing.com',
            ]);
        $admin->assignRole('admin');

        $pemilik = User::query()->where('email', 'pemilik@testing.com')->first()
            ?? User::factory()->create([
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
