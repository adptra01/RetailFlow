<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            ['name' => 'PT Indofood Sukses Makmur', 'contact_person' => 'Budi Santoso', 'phone' => '021-12345678', 'address' => 'Jakarta Pusat'],
            ['name' => 'CV Sinar Jaya Distribusi', 'contact_person' => 'Agus Wijaya', 'phone' => '031-87654321', 'address' => 'Surabaya'],
            ['name' => 'UD Berkah Abadi', 'contact_person' => 'Rudi Hermawan', 'phone' => '022-55443322', 'address' => 'Bandung'],
            ['name' => 'PT Sumber Makmur Sentosa', 'contact_person' => 'Hendra Gunawan', 'phone' => '061-11223344', 'address' => 'Medan'],
            ['name' => 'Toko Grosir Murni Jaya', 'contact_person' => 'Dwi Susanto', 'phone' => '0274-8877665', 'address' => 'Yogyakarta'],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::create($supplier);
        }
    }
}
