<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    public function definition(): array
    {
        $product = Product::inRandomOrder()->first() ?? Product::factory();
        $unit = $product->units()->inRandomOrder()->first()
            ?? ProductUnit::factory()->forProduct($product->id);

        $quantity = fake()->numberBetween(5, 200);
        $unitPriceMinor = ($unit->purchase_price_minor ?? $product->buy_price_minor ?? fake()->numberBetween(100000, 5000000));
        $discountMinor = fake()->numberBetween(0, 10000);
        $subtotalMinor = ($unitPriceMinor - $discountMinor) * $quantity;

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'discount_minor' => $discountMinor,
            'subtotal_minor' => $subtotalMinor,
            'received_quantity' => fake()->numberBetween(0, $quantity),
        ];
    }

    public function forPurchaseOrder(PurchaseOrder $po): static
    {
        return $this->state(fn () => ['purchase_order_id' => $po->id]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
