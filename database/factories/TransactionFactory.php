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
        $total = fake()->randomFloat(2, 5000, 500000);
        $paid = $total + fake()->randomFloat(2, 0, 100000);

        return [
            'customer' => fake()->name,
            'invoice_number' => 'INV-'.now()->format('YmdHis').'-'.strtoupper(fake()->unique()->bothify('####')),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'change_amount' => $paid - $total,
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
