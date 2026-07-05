<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        if ($users->isEmpty()) return;

        // Customer payments (for credit transactions)
        if (CustomerPayment::count() === 0) {
            $creditTransactions = Transaction::where('payment_status', 'credit')->get();
            $created = 0;

            foreach ($creditTransactions as $transaction) {
                $remaining = $transaction->total_amount - $transaction->paid_amount;
                if ($remaining <= 0) continue;

                $payAmount = $remaining > 0
                    ? rand(0, 1)
                        ? $remaining
                        : round($remaining * rand(5, 10) / 10, -3)
                    : 0;

                if ($payAmount <= 0) continue;

                CustomerPayment::create([
                    'customer_id' => $transaction->customer_id ?? Customer::inRandomOrder()->first()?->id,
                    'transaction_id' => $transaction->id,
                    'amount' => $payAmount,
                    'payment_date' => $transaction->created_at->addDays(rand(1, 14)),
                    'payment_method' => fake()->randomElement(['cash', 'transfer']),
                    'notes' => 'Pembayaran cicilan',
                    'user_id' => $users->random()->id,
                ]);
                $created++;
            }

            $this->command->info("Created {$created} customer payments.");
        }

        // Supplier payments
        if (SupplierPayment::count() === 0) {
            $purchaseOrders = PurchaseOrder::whereIn('status', ['received', 'partial'])->get();
            $created = 0;

            foreach ($purchaseOrders as $po) {
                if ($po->total_amount <= 0) continue;

                $payAmount = rand(0, 1)
                    ? $po->total_amount
                    : round($po->total_amount * rand(5, 10) / 10, -3);

                SupplierPayment::create([
                    'supplier_id' => $po->supplier_id ?? Supplier::inRandomOrder()->first()?->id,
                    'purchase_order_id' => $po->id,
                    'amount' => $payAmount,
                    'payment_date' => ($po->created_at ?? now())->addDays(rand(1, 14)),
                    'payment_method' => fake()->randomElement(['cash', 'transfer']),
                    'notes' => rand(0, 1) ? 'Pembayaran PO #' . $po->order_number : null,
                    'user_id' => $users->random()->id,
                ]);
                $created++;
            }

            $this->command->info("Created {$created} supplier payments.");
        }
    }
}
