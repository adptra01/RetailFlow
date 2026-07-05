<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::inRandomOrder()->first()?->id ?? Supplier::factory(),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'status' => fake()->randomElement(['pending', 'partial', 'received', 'cancelled']),
            'total_amount' => fake()->randomFloat(2, 50000, 2000000),
            'notes' => fake()->optional()->sentence(),
            'created_at' => fake()->dateTimeBetween('-60 days', 'now'),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function received(): static
    {
        return $this->state(fn () => ['status' => 'received']);
    }
}
