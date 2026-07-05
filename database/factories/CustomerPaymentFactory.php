<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerPaymentFactory extends Factory
{
    protected $model = CustomerPayment::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::inRandomOrder()->first()?->id ?? Customer::factory(),
            'transaction_id' => Transaction::inRandomOrder()->first()?->id ?? Transaction::factory(),
            'amount' => fake()->randomFloat(2, 10000, 500000),
            'payment_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'payment_method' => fake()->randomElement(['cash', 'transfer', 'debit_card']),
            'notes' => fake()->optional()->sentence(),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
        ];
    }
}
