<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockOpnameFactory extends Factory
{
    protected $model = StockOpname::class;

    public function definition(): array
    {
        $system = fake()->numberBetween(0, 100);
        $actual = $system + fake()->numberBetween(-5, 5);

        return [
            'product_id' => Product::inRandomOrder()->first()?->id ?? Product::factory(),
            'system_stock' => $system,
            'actual_stock' => max(0, $actual),
            'difference' => $actual - $system,
            'reason' => fake()->optional(0.7)->sentence(),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
