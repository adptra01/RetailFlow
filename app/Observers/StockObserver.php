<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;

class StockObserver
{
    public function updated(Product $product): void
    {
        if (! $product->isDirty('stock')) {
            return;
        }

        $original = $product->getOriginal('stock');
        $current = $product->stock;
        $change = $current - $original;

        // ponytail: clamp to 0 — column is UNSIGNED, negative after_stock would crash
        StockMovement::create([
            'product_id' => $product->id,
            'quantity_change' => $change,
            'before_stock' => $original,
            'after_stock' => max(0, $current),
            'reference_type' => 'adjustment',
            'user_id' => Auth::id(),
            'notes' => null,
        ]);
    }
}
