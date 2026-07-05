<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseReturnItemFactory extends Factory
{
    protected $model = PurchaseReturnItem::class;

    public function definition(): array
    {
        $product = Product::inRandomOrder()->first() ?? Product::factory();
        $unit = $product->units()->inRandomOrder()->first()
            ?? ProductUnit::factory()->forProduct($product->id);

        $qty = fake()->numberBetween(1, 10);
        $price = $unit->purchase_price ?: $product->buy_price ?: fake()->randomFloat(2, 1000, 30000);

        return [
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'qty' => $qty,
            'price' => $price,
            'subtotal' => $qty * $price,
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
