<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(rand(1, 3), true);

        return [
            'category_id' => Category::inRandomOrder()->first()?->id ?? Category::factory(),
            'name' => ucfirst($name),
            'slug' => str()->slug($name),
            'sku' => 'SKU-'.strtoupper(fake()->unique()->bothify('???#####')),
            'price' => fake()->randomFloat(2, 1000, 100000),
            'stock' => fake()->numberBetween(0, 100),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'is_unlimited_stock' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => fake()->numberBetween(0, 5),
        ]);
    }
}
