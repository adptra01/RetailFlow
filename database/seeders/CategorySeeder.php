<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Kopi & Espresso', 'slug' => 'kopi-espresso', 'description' => 'Kopi berbasis espresso dan racikan spesial'],
            ['name' => 'Non-Coffee', 'slug' => 'non-coffee', 'description' => 'Minuman non-kopi seperti matcha, taro, coklat'],
            ['name' => 'Makanan Ringan', 'slug' => 'makanan-ringan', 'description' => 'Pastry, cake, cookies, dan camilan'],
            ['name' => 'Makanan Berat', 'slug' => 'makanan-berat', 'description' => 'Nasi, pasta, dan hidangan utama'],
            ['name' => 'Minuman Segar', 'slug' => 'minuman-segar', 'description' => 'Jus, smoothies, es kelapa, dan minuman dingin'],
        ];

        foreach ($categories as $category) {
            Category::create($category);
        }
    }
}
