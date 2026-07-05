<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReturnSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        if ($users->isEmpty()) return;

        // Sales Returns
        if (SalesReturn::count() === 0) {
            $transactions = Transaction::inRandomOrder()->take(5)->get();

            foreach ($transactions as $transaction) {
                $items = TransactionItem::where('transaction_id', $transaction->id)->get();
                if ($items->isEmpty()) continue;

                $returnItems = $items->random(min(rand(1, 2), $items->count()));
                $total = 0;
                $return = SalesReturn::create([
                    'return_number' => 'RTS-' . $transaction->created_at->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -4)),
                    'transaction_id' => $transaction->id,
                    'customer_id' => $transaction->customer_id ?? Customer::inRandomOrder()->first()?->id,
                    'total' => 0,
                    'reason' => fake()->randomElement([
                        'Barang rusak', 'Kadaluarsa', 'Salah produk',
                        'Pelanggan komplain', 'Barang tidak sesuai',
                    ]),
                    'user_id' => $users->random()->id,
                    'created_at' => $transaction->created_at->addDays(rand(1, 3)),
                ]);

                foreach ($returnItems as $item) {
                    $qty = rand(1, $item->quantity);
                    $subtotal = $qty * $item->unit_price;
                    $total += $subtotal;

                    SalesReturnItem::create([
                        'sales_return_id' => $return->id,
                        'transaction_item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_unit_id' => $item->product_unit_id ?? ProductUnit::where('product_id', $item->product_id)->value('id'),
                        'qty' => $qty,
                        'price' => $item->unit_price,
                        'subtotal' => $subtotal,
                        'reason' => $return->reason,
                    ]);
                }

                if ($total > 0) $return->update(['total' => $total]);
            }
        }

        // Purchase Returns
        if (PurchaseReturn::count() === 0) {
            $purchaseOrders = PurchaseOrder::whereIn('status', ['received', 'partial'])
                ->inRandomOrder()->take(4)->get();

            foreach ($purchaseOrders as $po) {
                $items = PurchaseOrderItem::where('purchase_order_id', $po->id)->get();
                if ($items->isEmpty()) continue;

                $returnItems = $items->random(min(rand(1, 2), $items->count()));
                $total = 0;
                $return = PurchaseReturn::create([
                    'return_number' => 'RTP-' . $po->created_at->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -4)),
                    'purchase_order_id' => $po->id,
                    'supplier_id' => $po->supplier_id ?? Supplier::inRandomOrder()->first()?->id,
                    'total' => 0,
                    'reason' => fake()->randomElement([
                        'Barang cacat', 'Kualitas tidak sesuai', 'Salah kirim',
                        'Jumlah tidak sesuai', 'Barang rusak',
                    ]),
                    'user_id' => $users->random()->id,
                    'created_at' => ($po->created_at ?? now())->addDays(rand(1, 5)),
                ]);

                foreach ($returnItems as $item) {
                    $qty = rand(1, $item->quantity);
                    $subtotal = $qty * $item->unit_price;
                    $total += $subtotal;

                    PurchaseReturnItem::create([
                        'purchase_return_id' => $return->id,
                        'product_id' => $item->product_id,
                        'product_unit_id' => $item->product_unit_id,
                        'qty' => $qty,
                        'price' => $item->unit_price,
                        'subtotal' => $subtotal,
                        'reason' => $return->reason,
                    ]);
                }

                if ($total > 0) $return->update(['total' => $total]);
            }
        }

        $this->command->info('Created returns: ' . SalesReturn::count() . ' sales, ' . PurchaseReturn::count() . ' purchases.');
    }
}
