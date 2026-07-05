<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    public function definition(): array
    {
        $product = Product::inRandomOrder()->first() ?? Product::factory();
        $unit = $product->units()->inRandomOrder()->first()
            ?? ProductUnit::factory()->forProduct($product->id);

        $quantity = fake()->numberBetween(5, 200);
        $unitPrice = $unit->purchase_price ?: $product->buy_price ?: fake()->randomFloat(2, 1000, 50000);

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $quantity * $unitPrice,
            'received_quantity' => fake()->numberBetween(0, $quantity),
        ];
    }
}
