<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseReturnFactory extends Factory
{
    protected $model = PurchaseReturn::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::inRandomOrder()->first()?->id ?? PurchaseOrder::factory(),
            'supplier_id' => Supplier::inRandomOrder()->first()?->id ?? Supplier::factory(),
            'total' => fake()->randomFloat(2, 10000, 500000),
            'reason' => fake()->sentence(),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
