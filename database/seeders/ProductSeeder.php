<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $imageUrls = [
            'Espresso' => 'https://images.unsplash.com/photo-1445116572660-236099ec97a0?w=400&h=400&fit=crop',
            'Americano' => 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=400&h=400&fit=crop',
            'Cappuccino' => 'https://images.unsplash.com/photo-1534778101976-62847782c213?w=400&h=400&fit=crop',
            'Caffe Latte' => 'https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=400&h=400&fit=crop',
            'Mocha Latte' => 'https://images.unsplash.com/photo-1510707577719-ae7c14805e3a?w=400&h=400&fit=crop',
            'Caramel Macchiato' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=400&h=400&fit=crop',
            'Cold Brew' => 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?w=400&h=400&fit=crop',
            'Affogato' => 'https://images.unsplash.com/photo-1559305616-3f99cd43e353?w=400&h=400&fit=crop',
            'Matcha Latte' => 'https://images.unsplash.com/photo-1485808191679-5f86510681a2?w=400&h=400&fit=crop',
            'Taro Latte' => 'https://images.unsplash.com/photo-1504630083234-14187a9df0f5?w=400&h=400&fit=crop',
            'Chocolate Hazelnut' => 'https://images.unsplash.com/photo-1540189549336-e6e99c3679fe?w=400&h=400&fit=crop',
            'Red Velvet Latte' => 'https://images.unsplash.com/photo-1541783245831-57d6fb0926d3?w=400&h=400&fit=crop',
            'Vanilla Latte' => 'https://images.unsplash.com/photo-1574484284002-952d92456975?w=400&h=400&fit=crop',
            'Bandrek Susu' => 'https://images.unsplash.com/photo-1529042410759-befb1204b468?w=400&h=400&fit=crop',
            'Croissant' => 'https://images.unsplash.com/photo-1498804103079-a6351b050096?w=400&h=400&fit=crop',
            'Banana Bread' => 'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=400&h=400&fit=crop',
            'New York Cheesecake' => 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=400&h=400&fit=crop',
            'Chocolate Cookies' => 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=400&h=400&fit=crop',
            'Tiramisu' => 'https://images.unsplash.com/photo-1528975604071-b4dc52a2d18c?w=400&h=400&fit=crop',
            'Roti Bakar' => 'https://images.unsplash.com/photo-1509358271058-acd22cc93898?w=400&h=400&fit=crop',
            'Pisang Goreng' => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=400&h=400&fit=crop',
            'Kentang Goreng' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400&h=400&fit=crop',
            'Nasi Goreng Spesial' => 'https://images.unsplash.com/photo-1586985289688-ca3cf47d3e6e?w=400&h=400&fit=crop',
            'Spaghetti Aglio Olio' => 'https://images.unsplash.com/photo-1529692236671-f1f6cf9683ba?w=400&h=400&fit=crop',
            'Chicken Steak' => 'https://images.unsplash.com/photo-1594631252845-29fc4cc8cde9?w=400&h=400&fit=crop',
            'French Fries' => 'https://images.unsplash.com/photo-1559329007-40df8a9345d8?w=400&h=400&fit=crop',
            'Cireng Crispy' => 'https://images.unsplash.com/photo-1519676867240-f03562e64548?w=400&h=400&fit=crop',
            'Indomie Goreng' => 'https://images.unsplash.com/photo-1587314168485-3236d6710814?w=400&h=400&fit=crop',
            'Jus Alpukat' => 'https://images.unsplash.com/photo-1600271886742-f049cd451bba?w=400&h=400&fit=crop',
            'Jus Mangga' => 'https://images.unsplash.com/photo-1541625602330-2277a4c46182?w=400&h=400&fit=crop',
            'Smoothie Berry' => 'https://images.unsplash.com/photo-1558857563-b371033873b8?w=400&h=400&fit=crop',
            'Es Kelapa Muda' => 'https://images.unsplash.com/photo-1556881286-fc6915169721?w=400&h=400&fit=crop',
            'Lemon Tea' => 'https://images.unsplash.com/photo-1534352956036-cd81e27dd615?w=400&h=400&fit=crop',
            'Es Cincau' => 'https://images.unsplash.com/photo-1563227812-0ea4c22e6cc8?w=400&h=400&fit=crop',
        ];

        $productsByCategory = [
            'Kopi & Espresso' => [
                ['name' => 'Espresso', 'price' => 18000, 'sku' => 'SKU-KPI-001', 'is_unlimited_stock' => true],
                ['name' => 'Americano', 'price' => 22000, 'sku' => 'SKU-KPI-002', 'is_unlimited_stock' => true],
                ['name' => 'Cappuccino', 'price' => 28000, 'sku' => 'SKU-KPI-003', 'is_unlimited_stock' => true],
                ['name' => 'Caffe Latte', 'price' => 30000, 'sku' => 'SKU-KPI-004', 'is_unlimited_stock' => true],
                ['name' => 'Mocha Latte', 'price' => 32000, 'sku' => 'SKU-KPI-005', 'is_unlimited_stock' => true],
                ['name' => 'Caramel Macchiato', 'price' => 35000, 'sku' => 'SKU-KPI-006', 'is_unlimited_stock' => true],
                ['name' => 'Cold Brew', 'price' => 25000, 'sku' => 'SKU-KPI-007', 'is_unlimited_stock' => true],
                ['name' => 'Affogato', 'price' => 28000, 'sku' => 'SKU-KPI-008', 'is_unlimited_stock' => true],
            ],
            'Non-Coffee' => [
                ['name' => 'Matcha Latte', 'price' => 30000, 'sku' => 'SKU-NCF-001', 'is_unlimited_stock' => true],
                ['name' => 'Taro Latte', 'price' => 30000, 'sku' => 'SKU-NCF-002', 'is_unlimited_stock' => true],
                ['name' => 'Chocolate Hazelnut', 'price' => 32000, 'sku' => 'SKU-NCF-003', 'is_unlimited_stock' => true],
                ['name' => 'Red Velvet Latte', 'price' => 32000, 'sku' => 'SKU-NCF-004', 'is_unlimited_stock' => true],
                ['name' => 'Vanilla Latte', 'price' => 28000, 'sku' => 'SKU-NCF-005', 'is_unlimited_stock' => true],
                ['name' => 'Bandrek Susu', 'price' => 25000, 'sku' => 'SKU-NCF-006', 'is_unlimited_stock' => true],
            ],
            'Makanan Ringan' => [
                ['name' => 'Croissant', 'price' => 20000, 'sku' => 'SKU-MRG-001', 'stock' => 8],
                ['name' => 'Banana Bread', 'price' => 15000, 'sku' => 'SKU-MRG-002', 'stock' => 12],
                ['name' => 'New York Cheesecake', 'price' => 35000, 'sku' => 'SKU-MRG-003', 'stock' => 3],
                ['name' => 'Chocolate Cookies', 'price' => 12000, 'sku' => 'SKU-MRG-004', 'stock' => 25],
                ['name' => 'Tiramisu', 'price' => 30000, 'sku' => 'SKU-MRG-005', 'stock' => 0],
                ['name' => 'Roti Bakar', 'price' => 18000, 'sku' => 'SKU-MRG-006', 'stock' => 5],
                ['name' => 'Pisang Goreng', 'price' => 12000, 'sku' => 'SKU-MRG-007', 'is_unlimited_stock' => true],
                ['name' => 'Kentang Goreng', 'price' => 18000, 'sku' => 'SKU-MRG-008', 'is_unlimited_stock' => true],
            ],
            'Makanan Berat' => [
                ['name' => 'Nasi Goreng Spesial', 'price' => 30000, 'sku' => 'SKU-MBR-001', 'is_unlimited_stock' => true],
                ['name' => 'Spaghetti Aglio Olio', 'price' => 35000, 'sku' => 'SKU-MBR-002', 'is_unlimited_stock' => true],
                ['name' => 'Chicken Steak', 'price' => 45000, 'sku' => 'SKU-MBR-003', 'is_unlimited_stock' => true],
                ['name' => 'French Fries', 'price' => 20000, 'sku' => 'SKU-MBR-004', 'is_unlimited_stock' => true],
                ['name' => 'Cireng Crispy', 'price' => 15000, 'sku' => 'SKU-MBR-005', 'is_unlimited_stock' => true],
                ['name' => 'Indomie Goreng', 'price' => 12000, 'sku' => 'SKU-MBR-006', 'is_unlimited_stock' => true],
            ],
            'Minuman Segar' => [
                ['name' => 'Jus Alpukat', 'price' => 20000, 'sku' => 'SKU-SGR-001', 'is_unlimited_stock' => true],
                ['name' => 'Jus Mangga', 'price' => 18000, 'sku' => 'SKU-SGR-002', 'is_unlimited_stock' => true],
                ['name' => 'Smoothie Berry', 'price' => 25000, 'sku' => 'SKU-SGR-003', 'is_unlimited_stock' => true],
                ['name' => 'Es Kelapa Muda', 'price' => 15000, 'sku' => 'SKU-SGR-004', 'stock' => 15],
                ['name' => 'Lemon Tea', 'price' => 12000, 'sku' => 'SKU-SGR-005', 'stock' => 20],
                ['name' => 'Es Cincau', 'price' => 13000, 'sku' => 'SKU-SGR-006', 'stock' => 10],
            ],
        ];

        foreach ($productsByCategory as $categoryName => $products) {
            $category = Category::where('name', $categoryName)->first();
            if (! $category) {
                continue;
            }

            foreach ($products as $product) {
                Product::create([
                    'category_id' => $category->id,
                    'name' => $product['name'],
                    'slug' => str()->slug($product['name']),
                    'sku' => $product['sku'],
                    'price' => $product['price'],
                    'stock' => $product['stock'] ?? 0,
                    'is_unlimited_stock' => $product['is_unlimited_stock'] ?? false,
                    'image' => $imageUrls[$product['name']] ?? null,
                    'description' => $product['name'].' - Caffe Shop',
                    'is_active' => true,
                ]);
            }
        }
    }
}
