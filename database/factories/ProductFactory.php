<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(rand(1, 3), true);

        return [
            'category_id' => Category::inRandomOrder()->first()?->id ?? Category::factory(),
            'brand_id' => Brand::inRandomOrder()->first()?->id ?? null,
            'name' => ucfirst($name),
            'slug' => str()->slug($name),
            'sku' => 'SKU-'.strtoupper(fake()->unique()->bothify('???#####')),
            'barcode' => fake()->optional(0.3)->ean13(),
            'buy_price' => fake()->randomFloat(2, 500, 50000),
            'price' => fake()->randomFloat(2, 1000, 100000),
            'stock' => fake()->numberBetween(0, 100),
            'min_stock' => fake()->numberBetween(0, 10),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'is_unlimited_stock' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Product $product) {
            if ($product->units()->count() === 0) {
                $product->units()->create([
                    'name' => 'PCS',
                    'abbreviation' => 'PCS',
                    'conversion_factor' => 1,
                    'is_base' => true,
                    'price' => $product->price,
                    'purchase_price' => $product->buy_price ?? 0,
                ]);
            }
        });
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

    public function unlimited(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_unlimited_stock' => true,
            'stock' => 0,
        ]);
    }
}
