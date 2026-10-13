<?php

namespace Database\Seeders;

use App\Models\Category;
use Database\Seeders\Concerns\SeedsDemoTenant;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    use SeedsDemoTenant;

    public function run(): void
    {
        $this->demoTenant();

        $categories = [
            ['name' => 'Makanan Ringan', 'slug' => 'makanan-ringan', 'description' => 'Snack, keripik, biskuit, permen'],
            ['name' => 'Makanan Berat', 'slug' => 'makanan-berat', 'description' => 'Mie instan, sarden, bumbu masak'],
            ['name' => 'Minuman', 'slug' => 'minuman', 'description' => 'Kopi, teh, sirup, minuman serbuk'],
            ['name' => 'Air Mineral', 'slug' => 'air-mineral', 'description' => 'Air minum dalam kemasan galon dan botol'],
            ['name' => 'Susu & Olahan', 'slug' => 'susu-olahan', 'description' => 'Susu kental manis, susu bubuk, keju'],
            ['name' => 'Rumah Tangga', 'slug' => 'rumah-tangga', 'description' => 'Sabun, deterjen, pembersih lantai'],
            ['name' => 'Perawatan Tubuh', 'slug' => 'perawatan-tubuh', 'description' => 'Shampo, sabun mandi, pasta gigi'],
            ['name' => 'Kebutuhan Pokok', 'slug' => 'kebutuhan-pokok', 'description' => 'Beras, gula, minyak goreng, telur'],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
