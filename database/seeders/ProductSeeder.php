<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $brands = Brand::pluck('id', 'name');
        $suppliers = Supplier::pluck('id', 'name');
        $cat = fn ($name) => Category::where('name', $name)->value('id');

        $products = [
            ['name' => 'Indomie Goreng', 'sku' => 'IDM-GRG-001', 'category' => 'Makanan Berat', 'brand' => 'Indofood', 'supplier' => 'PT Indofood Sukses Makmur', 'price' => 3500, 'buy_price' => 2800, 'stock' => 200, 'min_stock' => 50, 'units' => [['name' => 'Bungkus', 'abbreviation' => 'Bks', 'conversion_factor' => 1, 'is_base' => true, 'price' => 3500, 'purchase_price' => 2800]]],
            ['name' => 'Indomie Kuah Rendang', 'sku' => 'IDM-END-001', 'category' => 'Makanan Berat', 'brand' => 'Indofood', 'supplier' => 'PT Indofood Sukses Makmur', 'price' => 3500, 'buy_price' => 2800, 'stock' => 150, 'min_stock' => 50, 'units' => [['name' => 'Bungkus', 'abbreviation' => 'Bks', 'conversion_factor' => 1, 'is_base' => true, 'price' => 3500, 'purchase_price' => 2800]]],
            ['name' => 'Indomie Iga Penyet', 'sku' => 'IDM-IGA-001', 'category' => 'Makanan Berat', 'brand' => 'Indofood', 'supplier' => 'PT Indofood Sukses Makmur', 'price' => 4000, 'buy_price' => 3200, 'stock' => 100, 'min_stock' => 30, 'units' => [['name' => 'Bungkus', 'abbreviation' => 'Bks', 'conversion_factor' => 1, 'is_base' => true, 'price' => 4000, 'purchase_price' => 3200]]],
            ['name' => 'Indomie Karton', 'sku' => 'IDM-KRT-001', 'category' => 'Makanan Berat', 'brand' => 'Indofood', 'supplier' => 'PT Indofood Sukses Makmur', 'price' => 95000, 'buy_price' => 76000, 'stock' => 20, 'min_stock' => 5, 'barcode' => '8991002100123', 'units' => [['name' => 'Karton', 'abbreviation' => 'Krt', 'conversion_factor' => 40, 'is_base' => false, 'price' => 95000, 'purchase_price' => 76000], ['name' => 'Bungkus', 'abbreviation' => 'Bks', 'conversion_factor' => 1, 'is_base' => true, 'price' => 3500, 'purchase_price' => 2800]]],
            ['name' => 'Beras Ramos 5kg', 'sku' => 'BRAS-RM5-001', 'category' => 'Kebutuhan Pokok', 'brand' => null, 'supplier' => 'UD Berkah Abadi', 'price' => 72000, 'buy_price' => 65000, 'stock' => 30, 'min_stock' => 10, 'units' => [['name' => 'Karung 5kg', 'abbreviation' => '5kg', 'conversion_factor' => 1, 'is_base' => true, 'price' => 72000, 'purchase_price' => 65000]]],
            ['name' => 'Gula Pasir 1kg', 'sku' => 'GULA-PSR-001', 'category' => 'Kebutuhan Pokok', 'brand' => null, 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 18000, 'buy_price' => 15500, 'stock' => 50, 'min_stock' => 20, 'units' => [['name' => 'Kg', 'abbreviation' => 'Kg', 'conversion_factor' => 1, 'is_base' => true, 'price' => 18000, 'purchase_price' => 15500]]],
            ['name' => 'Minyak Goreng Sania 2L', 'sku' => 'MYK-SAN-002', 'category' => 'Kebutuhan Pokok', 'brand' => null, 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 35000, 'buy_price' => 30000, 'stock' => 25, 'min_stock' => 10, 'units' => [['name' => 'Botol 2L', 'abbreviation' => '2L', 'conversion_factor' => 1, 'is_base' => true, 'price' => 35000, 'purchase_price' => 30000]]],
            ['name' => 'Kopi Kapal Api 200gr', 'sku' => 'KPI-KPL-200', 'category' => 'Minuman', 'brand' => 'Mayora', 'supplier' => 'PT Sumber Makmur Sentosa', 'price' => 16000, 'buy_price' => 13000, 'stock' => 40, 'min_stock' => 15, 'barcode' => '8992802100125', 'units' => [['name' => 'Renceng', 'abbreviation' => 'Rcg', 'conversion_factor' => 1, 'is_base' => true, 'price' => 16000, 'purchase_price' => 13000]]],
            ['name' => 'Teh Botol Sosro 500ml', 'sku' => 'TEH-SSR-500', 'category' => 'Minuman', 'brand' => 'Sinar Sosro', 'supplier' => 'PT Sumber Makmur Sentosa', 'price' => 6000, 'buy_price' => 4800, 'stock' => 60, 'min_stock' => 20, 'barcode' => '8991002101024', 'units' => [['name' => 'Botol', 'abbreviation' => 'Btl', 'conversion_factor' => 1, 'is_base' => true, 'price' => 6000, 'purchase_price' => 4800], ['name' => 'Dus', 'abbreviation' => 'Dus', 'conversion_factor' => 24, 'is_base' => false, 'price' => 130000, 'purchase_price' => 110000]]],
            ['name' => 'Aqua Botol 600ml', 'sku' => 'AQUA-600-001', 'category' => 'Air Mineral', 'brand' => 'Aqua Danone', 'supplier' => 'UD Berkah Abadi', 'price' => 3000, 'buy_price' => 2200, 'stock' => 200, 'min_stock' => 50, 'barcode' => '8886008101123', 'units' => [['name' => 'Botol', 'abbreviation' => 'Btl', 'conversion_factor' => 1, 'is_base' => true, 'price' => 3000, 'purchase_price' => 2200], ['name' => 'Dus', 'abbreviation' => 'Dus', 'conversion_factor' => 24, 'is_base' => false, 'price' => 65000, 'purchase_price' => 52000]]],
            ['name' => 'Aqua Galon 19L', 'sku' => 'AQUA-GLN-001', 'category' => 'Air Mineral', 'brand' => 'Aqua Danone', 'supplier' => 'UD Berkah Abadi', 'price' => 22000, 'buy_price' => 18000, 'stock' => 10, 'min_stock' => 5, 'units' => [['name' => 'Galon', 'abbreviation' => 'Gln', 'conversion_factor' => 1, 'is_base' => true, 'price' => 22000, 'purchase_price' => 18000]]],
            ['name' => 'Susu Kental Manis Frisian Flag 1kg', 'sku' => 'SKM-FF-001', 'category' => 'Susu & Olahan', 'brand' => 'Nestle', 'supplier' => 'PT Indofood Sukses Makmur', 'price' => 22000, 'buy_price' => 18500, 'stock' => 30, 'min_stock' => 10, 'units' => [['name' => 'Kaleng', 'abbreviation' => 'Klg', 'conversion_factor' => 1, 'is_base' => true, 'price' => 22000, 'purchase_price' => 18500]]],
            ['name' => 'Dancow 400gr', 'sku' => 'DNCW-400-001', 'category' => 'Susu & Olahan', 'brand' => 'Nestle', 'supplier' => 'PT Indofood Sukses Makmur', 'price' => 45000, 'buy_price' => 38000, 'stock' => 15, 'min_stock' => 5, 'units' => [['name' => 'Kotak', 'abbreviation' => 'Ktk', 'conversion_factor' => 1, 'is_base' => true, 'price' => 45000, 'purchase_price' => 38000]]],
            ['name' => 'Taro Net 90gr', 'sku' => 'TARO-090-001', 'category' => 'Makanan Ringan', 'brand' => 'Mayora', 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 12000, 'buy_price' => 9500, 'stock' => 40, 'min_stock' => 15, 'units' => [['name' => 'Bungkus', 'abbreviation' => 'Bks', 'conversion_factor' => 1, 'is_base' => true, 'price' => 12000, 'purchase_price' => 9500]]],
            ['name' => 'Oreo 137gr', 'sku' => 'OREO-137-001', 'category' => 'Makanan Ringan', 'brand' => 'Mayora', 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 13000, 'buy_price' => 10500, 'stock' => 35, 'min_stock' => 10, 'barcode' => '8991002104329', 'units' => [['name' => 'Bungkus', 'abbreviation' => 'Bks', 'conversion_factor' => 1, 'is_base' => true, 'price' => 13000, 'purchase_price' => 10500]]],
            ['name' => 'Roma Malkist 145gr', 'sku' => 'ROMA-MLK-145', 'category' => 'Makanan Ringan', 'brand' => 'Mayora', 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 8000, 'buy_price' => 6200, 'stock' => 50, 'min_stock' => 20, 'units' => [['name' => 'Bungkus', 'abbreviation' => 'Bks', 'conversion_factor' => 1, 'is_base' => true, 'price' => 8000, 'purchase_price' => 6200]]],
            ['name' => 'Sabun Lifebuoy 165gr', 'sku' => 'SBN-LFB-165', 'category' => 'Perawatan Tubuh', 'brand' => 'Unilever', 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 5500, 'buy_price' => 4200, 'stock' => 30, 'min_stock' => 10, 'units' => [['name' => 'Pcs', 'abbreviation' => 'Pcs', 'conversion_factor' => 1, 'is_base' => true, 'price' => 5500, 'purchase_price' => 4200]]],
            ['name' => 'Pepsodent 120gr', 'sku' => 'PPSD-120-001', 'category' => 'Perawatan Tubuh', 'brand' => 'Unilever', 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 9000, 'buy_price' => 7000, 'stock' => 25, 'min_stock' => 10, 'units' => [['name' => 'Pcs', 'abbreviation' => 'Pcs', 'conversion_factor' => 1, 'is_base' => true, 'price' => 9000, 'purchase_price' => 7000]]],
            ['name' => 'Rinso 900gr', 'sku' => 'RNSO-900-001', 'category' => 'Rumah Tangga', 'brand' => 'Unilever', 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 18000, 'buy_price' => 15000, 'stock' => 20, 'min_stock' => 8, 'units' => [['name' => 'Kotak', 'abbreviation' => 'Ktk', 'conversion_factor' => 1, 'is_base' => true, 'price' => 18000, 'purchase_price' => 15000]]],
            ['name' => 'So Klin 900ml', 'sku' => 'SKLN-900-001', 'category' => 'Rumah Tangga', 'brand' => 'Wings Group', 'supplier' => 'CV Sinar Jaya Distribusi', 'price' => 14000, 'buy_price' => 11500, 'stock' => 15, 'min_stock' => 8, 'units' => [['name' => 'Botol', 'abbreviation' => 'Btl', 'conversion_factor' => 1, 'is_base' => true, 'price' => 14000, 'purchase_price' => 11500]]],
            ['name' => 'Kecap ABC 275ml', 'sku' => 'KCP-ABC-275', 'category' => 'Makanan Berat', 'brand' => 'ABC Group', 'supplier' => 'PT Sumber Makmur Sentosa', 'price' => 8000, 'buy_price' => 6200, 'stock' => 20, 'min_stock' => 10, 'units' => [['name' => 'Botol', 'abbreviation' => 'Btl', 'conversion_factor' => 1, 'is_base' => true, 'price' => 8000, 'purchase_price' => 6200]]],
            ['name' => 'Telur Ayam 1kg', 'sku' => 'TLR-001-001', 'category' => 'Kebutuhan Pokok', 'brand' => null, 'supplier' => 'UD Berkah Abadi', 'price' => 30000, 'buy_price' => 26000, 'stock' => 20, 'min_stock' => 5, 'units' => [['name' => 'Kg', 'abbreviation' => 'Kg', 'conversion_factor' => 1, 'is_base' => true, 'price' => 30000, 'purchase_price' => 26000]]],
            ['name' => 'Sirup Marjan 460ml', 'sku' => 'MRJN-460-001', 'category' => 'Minuman', 'brand' => 'ABC Group', 'supplier' => 'PT Sumber Makmur Sentosa', 'price' => 18000, 'buy_price' => 14500, 'stock' => 0, 'min_stock' => 10, 'is_unlimited_stock' => false, 'units' => [['name' => 'Botol', 'abbreviation' => 'Btl', 'conversion_factor' => 1, 'is_base' => true, 'price' => 18000, 'purchase_price' => 14500]]],
        ];

        foreach ($products as $p) {
            $units = $p['units'] ?? [
                ['name' => 'Pcs', 'abbreviation' => 'Pcs', 'conversion_factor' => 1, 'is_base' => true, 'price' => $p['price'], 'purchase_price' => $p['buy_price'] ?? 0],
            ];
            unset($p['units']);

            $product = Product::create([
                'category_id' => $cat($p['category']),
                'brand_id' => $p['brand'] ? ($brands[$p['brand']] ?? null) : null,
                'supplier_id' => $suppliers[$p['supplier']] ?? null,
                'name' => $p['name'],
                'slug' => str()->slug($p['name']),
                'sku' => $p['sku'],
                'barcode' => $p['barcode'] ?? null,
                'buy_price' => $p['buy_price'] ?? 0,
                'price' => $p['price'],
                'stock' => $p['stock'] ?? 0,
                'min_stock' => $p['min_stock'] ?? 0,
                'is_unlimited_stock' => $p['is_unlimited_stock'] ?? false,
                'is_active' => true,
                'description' => $p['name'],
            ]);

            foreach ($units as $unit) {
                $product->units()->create($unit);
            }
        }
    }
}
