<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoodsReceiptItemFactory extends Factory
{
    protected $model = GoodsReceiptItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 10);
        $unitPriceMinor = fake()->numberBetween(5000, 150000);

        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'purchase_order_item_id' => PurchaseOrderItem::factory(),
            'quantity' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'subtotal_minor' => $unitPriceMinor * $quantity,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forGoodsReceipt(GoodsReceipt $gr): static
    {
        return $this->state(fn () => ['goods_receipt_id' => $gr->id]);
    }
}
