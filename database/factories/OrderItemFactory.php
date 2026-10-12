<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPriceMinor = fake()->numberBetween(5000, 150000);

        return [
            'order_id' => Order::factory(),
            'menu_id' => Menu::factory(),
            'menu_name' => fake()->words(2, true),
            'unit_price_minor' => $unitPriceMinor,
            'quantity' => $quantity,
            'subtotal_minor' => $unitPriceMinor * $quantity,
            'discount_minor' => 0,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn () => ['order_id' => $order->id]);
    }
}
