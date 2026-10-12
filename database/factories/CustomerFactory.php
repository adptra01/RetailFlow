<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->optional()->address(),
            'credit_limit' => fake()->randomFloat(2, 0, 5000000),
            'balance' => fake()->randomFloat(2, 0, 500000),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function withCredit(): static
    {
        return $this->state(fn () => [
            'credit_limit' => fake()->randomFloat(2, 500000, 5000000),
        ]);
    }
}
