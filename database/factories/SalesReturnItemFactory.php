<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesReturnItem;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesReturnItemFactory extends Factory
{
    protected $model = SalesReturnItem::class;

    public function definition(): array
    {
        $product = Product::inRandomOrder()->first() ?? Product::factory();
        $unit = $product->units()->inRandomOrder()->first()
            ?? ProductUnit::factory()->forProduct($product->id);

        $qty = fake()->numberBetween(1, 5);
        // Harga master masih desimal major; nilai kebenaran baris disimpan minor.
        $unitPriceMinor = Money::fromMajor((float) ($unit->price ?: $product->price ?: 0))->minor();

        if ($unitPriceMinor <= 0) {
            $unitPriceMinor = fake()->numberBetween(1000, 50000);
        }

        return [
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'qty' => $qty,
            'unit_price_minor' => $unitPriceMinor,
            'discount_minor' => 0,
            'subtotal_minor' => $unitPriceMinor * $qty,
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
