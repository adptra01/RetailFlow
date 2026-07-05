<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierPaymentFactory extends Factory
{
    protected $model = SupplierPayment::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::inRandomOrder()->first()?->id ?? Supplier::factory(),
            'purchase_order_id' => PurchaseOrder::inRandomOrder()->first()?->id ?? PurchaseOrder::factory(),
            'amount' => fake()->randomFloat(2, 50000, 2000000),
            'payment_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'payment_method' => fake()->randomElement(['cash', 'transfer', 'debit_card']),
            'notes' => fake()->optional()->sentence(),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
        ];
    }
}
