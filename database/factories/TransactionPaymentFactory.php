<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionPayment>
 */
class TransactionPaymentFactory extends Factory
{
    protected $model = TransactionPayment::class;

    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'payment_method' => PaymentMethod::Cash->value,
            'amount_minor' => fake()->numberBetween(1_000, 100_000),
            'payment_verification' => PaymentVerification::CashierRecorded->value,
            'reference' => null,
            'notes' => null,
        ];
    }
}
