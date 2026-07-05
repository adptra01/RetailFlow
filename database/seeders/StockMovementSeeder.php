<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class StockMovementSeeder extends Seeder
{
    public function run(): void
    {
        if (StockMovement::count() > 0) {
            $this->command->warn('Stock movements already exist, skipping.');
            return;
        }

        $users = User::all();
        if ($users->isEmpty()) return;

        $transactionItems = TransactionItem::with('product')->get();
        $created = 0;

        foreach ($transactionItems as $item) {
            $product = $item->product;
            if (!$product || $product->is_unlimited_stock) continue;

            StockMovement::create([
                'product_id' => $product->id,
                'quantity_change' => -$item->quantity,
                'before_stock' => 0,
                'after_stock' => 0,
                'reference_type' => 'App\Models\Transaction',
                'reference_id' => $item->transaction_id,
                'user_id' => $users->random()->id,
                'notes' => 'Penjualan #' . $item->transaction_id,
                'created_at' => $item->created_at ?? now(),
            ]);
            $created++;
        }

        $this->command->info("Created {$created} stock movements from transactions.");
    }
}
