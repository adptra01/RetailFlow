<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'notes' => fake()->optional()->sentence(),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
