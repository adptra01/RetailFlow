<?php

namespace Database\Factories;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['percent', 'nominal']);

        return [
            'name' => fake()->unique()->words(3, true),
            'code' => 'PROMO-'.strtoupper(fake()->unique()->bothify('####')),
            'discount_type' => $type,
            'discount_value' => $type === 'percent' ? fake()->numberBetween(5, 50) : fake()->numberBetween(1000, 100_000),
            'scope' => fake()->randomElement(['product', 'transaction']),
            'min_purchase_minor' => fake()->randomElement([0, 25_000, 50_000]),
            'max_discount_minor' => fake()->optional(0.3)->numberBetween(10_000, 200_000),
            'starts_at' => fake()->optional(0.7)->dateTimeBetween('-30 days', '-1 day'),
            'ends_at' => fake()->optional(0.7)->dateTimeBetween('+1 day', '+60 days'),
            'is_active' => true,
            'priority' => fake()->numberBetween(0, 10),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Promosi persen untuk seluruh transaksi, aktif sekarang.
     */
    public function percent(float|int $value = 10, int $minPurchaseMinor = 0): static
    {
        return $this->state(fn () => [
            'discount_type' => 'percent',
            'discount_value' => (int) $value,
            'scope' => 'transaction',
            'min_purchase_minor' => $minPurchaseMinor,
            'max_discount_minor' => null,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    /**
     * Promosi nominal (minor) untuk seluruh transaksi, aktif sekarang.
     */
    public function nominal(int $valueMinor = 5000, int $minPurchaseMinor = 0): static
    {
        return $this->state(fn () => [
            'discount_type' => 'nominal',
            'discount_value' => $valueMinor,
            'scope' => 'transaction',
            'min_purchase_minor' => $minPurchaseMinor,
            'max_discount_minor' => null,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    /**
     * Promosi untuk produk tertentu, aktif sekarang, tanpa syarat pembelian.
     */
    public function productScope(float|int $value = 10): static
    {
        return $this->state(fn () => [
            'discount_type' => 'percent',
            'discount_value' => (int) $value,
            'scope' => 'product',
            'min_purchase_minor' => 0,
            'max_discount_minor' => null,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ]);
    }
}
