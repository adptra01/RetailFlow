<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        $change = fake()->randomElement([
            fake()->numberBetween(-50, -1),
            fake()->numberBetween(1, 100),
        ]);

        $before = fake()->numberBetween(0, 200);
        $after = max(0, $before + $change);

        return [
            'product_id' => Product::inRandomOrder()->first()?->id ?? Product::factory(),
            'quantity_change' => $change,
            'before_stock' => $before,
            'after_stock' => $after,
            'reference_type' => fake()->randomElement(['App\Models\Transaction', 'App\Models\PurchaseOrder', 'App\Models\StockOpname']),
            'reference_id' => fake()->numberBetween(1, 100),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'notes' => fake()->optional()->sentence(),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function sale(): static
    {
        return $this->state(fn () => [
            'quantity_change' => fake()->numberBetween(-20, -1),
            'reference_type' => 'App\Models\Transaction',
        ]);
    }

    public function purchase(): static
    {
        return $this->state(fn () => [
            'quantity_change' => fake()->numberBetween(1, 100),
            'reference_type' => 'App\Models\PurchaseOrder',
        ]);
    }
}
