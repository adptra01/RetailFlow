<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierPaymentFactory extends Factory
{
    protected $model = SupplierPayment::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'purchase_order_id' => PurchaseOrder::factory(),
            'amount_minor' => fake()->numberBetween(50000, 2000000),
            'payment_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'payment_method' => fake()->randomElement(['cash', 'transfer', 'debit_card']),
            'notes' => fake()->optional()->sentence(),
            'user_id' => User::factory(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
