<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class MenuFactory extends Factory
{
    protected $model = Menu::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(3),
            'sku' => 'MNU-'.fake()->unique()->numerify('####'),
            'barcode' => fake()->optional(0.3)->ean13(),
            'description' => fake()->optional()->sentence(),
            'price_minor' => fake()->numberBetween(5000, 150000),
            'preparation_time' => fake()->optional(0.5)->numberBetween(2, 30),
            'is_available' => true,
            'is_featured' => fake()->boolean(20),
            'image' => null,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn () => ['is_available' => false]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
