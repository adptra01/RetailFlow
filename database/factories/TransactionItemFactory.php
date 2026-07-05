<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionItem>
 */
class TransactionItemFactory extends Factory
{
    public function definition(): array
    {
        $product = Product::inRandomOrder()->first() ?? Product::factory();
        $quantity = fake()->numberBetween(1, 10);
        $price = $product->price ?? fake()->randomFloat(2, 1000, 50000);

        return [
            'transaction_id' => Transaction::inRandomOrder()->first()?->id ?? Transaction::factory(),
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'subtotal' => $quantity * $price,
        ];
    }
}
