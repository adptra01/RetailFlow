<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            ['name' => 'Indofood', 'description' => 'Mie instan, bumbu, makanan ringan'],
            ['name' => 'Unilever', 'description' => 'Sabun, deterjen, produk rumah tangga'],
            ['name' => 'Nestle', 'description' => 'Susu, kopi, makanan bayi'],
            ['name' => 'Mayora', 'description' => 'Biskuit, permen, kopi, minuman'],
            ['name' => 'Aqua Danone', 'description' => 'Air mineral'],
            ['name' => 'Wings Group', 'description' => 'Sabun, deterjen, pembersih'],
            ['name' => 'GarudaFood', 'description' => 'Snack dan makanan ringan'],
            ['name' => 'ABC Group', 'description' => 'Kecap, saus, sirup'],
            ['name' => 'KINO Indonesia', 'description' => 'Produk perawatan tubuh'],
            ['name' => 'Sinar Sosro', 'description' => 'Teh botol dan minuman sachet'],
        ];

        foreach ($brands as $brand) {
            Brand::create($brand);
        }
    }
}
