<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseReturnItemFactory extends Factory
{
    protected $model = PurchaseReturnItem::class;

    public function definition(): array
    {
        $product = Product::inRandomOrder()->first() ?? Product::factory();
        $unit = $product->units()->inRandomOrder()->first()
            ?? ProductUnit::factory()->forProduct($product->id);

        $qty = fake()->numberBetween(1, 10);
        $priceMinor = ($unit->purchase_price_minor ?? $product->buy_price_minor ?? fake()->numberBetween(100000, 3000000));
        $discountMinor = fake()->numberBetween(0, 10000);
        $subtotalMinor = ($priceMinor - $discountMinor) * $qty;

        return [
            'purchase_return_id' => PurchaseReturn::factory(),
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'qty' => $qty,
            'price_minor' => $priceMinor,
            'discount_minor' => $discountMinor,
            'subtotal_minor' => $subtotalMinor,
            'reason' => fake()->optional()->sentence(),
        ];
    }

    public function forPurchaseReturn(PurchaseReturn $pr): static
    {
        return $this->state(fn () => ['purchase_return_id' => $pr->id]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
