<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $statuses = ['pending', 'preparing', 'ready', 'served', 'paid', 'cancelled'];
        $paymentStatuses = ['unpaid', 'partial', 'paid'];

        return [
            'customer_id' => null,
            'customer_name' => fake()->optional(0.7)->name(),
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'table_number' => fake()->optional(0.6)->numerify('##'),
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 0,
            'paid_minor' => 0,
            'change_minor' => 0,
            'status' => fake()->randomElement($statuses),
            'payment_method' => fake()->randomElement(['cash', 'transfer', 'qr', 'card']),
            'payment_status' => fake()->randomElement($paymentStatuses),
            'notes' => fake()->optional()->sentence(),
            'user_id' => User::factory(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'payment_status' => 'unpaid']);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid', 'payment_status' => 'paid']);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
