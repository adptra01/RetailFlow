<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        // §17.1: nilai kebenaran integer minor unit (IDR exponent 0).
        $total = fake()->numberBetween(5000, 500000);
        $paid = $total + fake()->numberBetween(0, 100000);

        return [
            'customer_name' => fake()->name,
            'invoice_number' => 'INV-'.now()->format('YmdHis').'-'.strtoupper(fake()->unique()->bothify('####')),
            'subtotal_minor' => $total,
            'discount_minor' => 0,
            'total_minor' => $total,
            'paid_minor' => $paid,
            'change_minor' => $paid - $total,
            'payment_method' => 'cash',
            'notes' => fake()->optional()->sentence(),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => fake()->dateTimeBetween('-1 day', 'now'),
        ]);
    }
}
