<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        $subtotal = fake()->numberBetween(50000, 2000000);
        $discount = fake()->numberBetween(0, 100000);
        $total = $subtotal - $discount;

        return [
            'supplier_id' => Supplier::factory(),
            'user_id' => User::factory(),
            'status' => fake()->randomElement(['draft', 'pending', 'partial', 'received', 'cancelled']),
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'total_minor' => $total,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function received(): static
    {
        return $this->state(fn () => ['status' => 'received']);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
