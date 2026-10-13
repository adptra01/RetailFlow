<?php

namespace Database\Seeders\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Mengikat tenant default saat seeder dijalankan dari CLI (§12).
 *
 * Scope tenant global hanya aktif bila `TenantContext` terikat. Tanpa ikatan
 * itu, seeder CLI menulis baris dengan `tenant_id` NULL — data yang TIDAK
 * akan pernah terlihat di aplikasi, karena setiap permintaan selalu menyaring
 * tenant. Sebelumnya seluruh data demo (produk, pelanggan, transaksi, ...)
 * lahir tanpa tenant.
 *
 * Trait ini membuat setiap seeder dapat dijalankan sendiri-sendiri
 * (`php artisan db:seed --class=ProductSeeder`) tanpa bergantung urutan
 * DatabaseSeeder: tenant diambil, dan bila belum ada, `TenantSeeder` dipanggil
 * lebih dulu.
 */
trait SeedsDemoTenant
{
    protected function demoTenant(): Tenant
    {
        $tenant = Tenant::query()->orderBy('id')->first();

        if ($tenant === null) {
            $this->call(TenantSeeder::class);
            $tenant = Tenant::query()->orderBy('id')->firstOrFail();
        }

        app(TenantContext::class)->set($tenant);

        return $tenant;
    }
}
