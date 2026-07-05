<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesReturnFactory extends Factory
{
    protected $model = SalesReturn::class;

    public function definition(): array
    {
        $transaction = Transaction::inRandomOrder()->first() ?? Transaction::factory();

        return [
            'transaction_id' => $transaction->id,
            'customer_id' => $transaction->customer_id ?? Customer::inRandomOrder()->first()?->id,
            'total' => fake()->randomFloat(2, 5000, 200000),
            'reason' => fake()->sentence(),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
