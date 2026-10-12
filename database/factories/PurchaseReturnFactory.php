<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseReturnFactory extends Factory
{
    protected $model = PurchaseReturn::class;

    public function definition(): array
    {
        $subtotal = fake()->numberBetween(10000, 500000);
        $discount = fake()->numberBetween(0, 10000);
        $total = $subtotal - $discount;

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'supplier_id' => Supplier::factory(),
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'total_minor' => $total,
            'reason' => fake()->sentence(),
            'user_id' => User::factory(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
