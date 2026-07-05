<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PurchaseOrderSeeder extends Seeder
{
    public function run(): void
    {
        if (PurchaseOrder::count() > 0) {
            $this->command->warn('Purchase orders already exist, skipping.');
            return;
        }

        $users = User::all();
        $suppliers = Supplier::all();

        if ($suppliers->isEmpty()) {
            $this->command->warn('No suppliers, skipping PurchaseOrderSeeder.');
            return;
        }

        $products = Product::with('units')->get();
        if ($products->isEmpty()) {
            $this->command->warn('No products, skipping PurchaseOrderSeeder.');
            return;
        }

        $statuses = ['pending', 'partial', 'received', 'cancelled'];
        $weights = [3, 2, 4, 1];
        $pool = [];
        foreach ($statuses as $i => $s) {
            $pool = array_merge($pool, array_fill(0, $weights[$i], $s));
        }

        for ($i = 0; $i < 15; $i++) {
            $supplier = $suppliers->random();
            $status = $pool[array_rand($pool)];

            $createdAt = Carbon::now()->subDays(rand(0, 45))->setTime(rand(8, 17), rand(0, 59));
            $po = PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'user_id' => $users->random()->id,
                'status' => $status,
                'total_amount' => 0,
                'notes' => rand(0, 1) ? fake()->sentence() : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $total = 0;
            $itemCount = rand(1, 5);
            $selectedProducts = $products->random(min($itemCount, $products->count()));

            foreach ($selectedProducts as $product) {
                $unit = $product->units()->where('is_base', true)->first() ?? $product->units()->first();
                if (!$unit) continue;

                $quantity = rand(5, 50);
                $basePrice = $unit->purchase_price ?: $product->buy_price ?: 0;
                $unitPrice = round(max(100, $basePrice * (1 + rand(-1, 3) * 0.05)), -2);
                $subtotal = $quantity * $unitPrice;
                $total += $subtotal;

                $received = $status === 'received' ? $quantity
                    : ($status === 'partial' ? rand(1, $quantity) : 0);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'received_quantity' => $received,
                ]);
            }

            if ($total > 0) {
                $po->update(['total_amount' => $total]);
            }
        }

        $this->command->info('Created ' . PurchaseOrder::count() . ' purchase orders.');
    }
}
