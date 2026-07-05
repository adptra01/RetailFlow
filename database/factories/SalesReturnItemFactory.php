<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesReturnItem;
use App\Models\TransactionItem;
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
        $price = $unit->price ?: $product->price ?: fake()->randomFloat(2, 1000, 50000);

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
