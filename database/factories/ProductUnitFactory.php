<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductUnitFactory extends Factory
{
    protected $model = ProductUnit::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['Pcs', 'Pack', 'Dus', 'Karton', 'Botol', 'Kg']),
            'abbreviation' => fn (array $attrs) => str($attrs['name'])->substr(0, 3)->value(),
            'conversion_factor' => 1,
            'is_base' => true,
            'price' => fake()->randomFloat(2, 1000, 100000),
            'purchase_price' => fake()->randomFloat(2, 500, 80000),
            'is_active' => true,
        ];
    }

    public function forProduct(int $productId): static
    {
        return $this->state(fn () => ['product_id' => $productId]);
    }

    public function base(): static
    {
        return $this->state(fn () => [
            'conversion_factor' => 1,
            'is_base' => true,
        ]);
    }

    public function subUnit(int $factor): static
    {
        return $this->state(fn () => [
            'name' => 'Pcs',
            'abbreviation' => 'Pcs',
            'conversion_factor' => $factor,
            'is_base' => false,
        ]);
    }
}
