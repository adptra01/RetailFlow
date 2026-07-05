<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Database\Seeder;

class StockOpnameSeeder extends Seeder
{
    public function run(): void
    {
        if (StockOpname::count() > 0) {
            $this->command->warn('Stock opnames already exist, skipping.');
            return;
        }

        $users = User::all();
        $products = Product::where('is_unlimited_stock', false)->get();

        if ($products->isEmpty() || $users->isEmpty()) return;

        $products->random(min(5, $products->count()))->each(function ($product) use ($users) {
            $system = $product->stock;
            $diff = rand(-3, 5);
            $actual = max(0, $system + $diff);

            StockOpname::create([
                'product_id' => $product->id,
                'system_stock' => $system,
                'actual_stock' => $actual,
                'difference' => $actual - $system,
                'reason' => $diff !== 0 ? fake()->optional(0.6)->sentence() : null,
                'user_id' => $users->random()->id,
                'created_at' => now()->subDays(rand(0, 14))->setTime(rand(9, 16), rand(0, 59)),
            ]);
        });

        $this->command->info('Created stock opnames.');
    }
}
