<?php

namespace Database\Factories;

use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'sku' => 'ING-'.strtoupper(fake()->unique()->bothify('####')),
            'unit' => fake()->randomElement(['pcs', 'gr', 'ml', 'pack', 'kg']),
            'stock_qty' => fake()->numberBetween(0, 10000),
            'cost_minor' => fake()->numberBetween(500, 500_000),
            'min_stock_qty' => fake()->numberBetween(0, 50),
            'is_active' => true,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
