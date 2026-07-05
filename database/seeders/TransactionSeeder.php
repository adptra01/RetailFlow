<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        $products = Product::all();

        if ($products->isEmpty()) {
            $this->command->warn('Tidak ada produk, lewati TransactionSeeder.');

            return;
        }

        $customers = [
            'Ahmad Fauzi', 'Siti Nurhaliza', 'Bambang Wijaya', 'Dewi Lestari',
            'Rudi Hartono', 'Mega Putri', 'Agus Salim', 'Rina Marlina',
            'Doni Pratama', 'Indah Permata', 'Fajar Setiawan', 'Wulan Sari',
            'Rizky Ramadhan', 'Ratna Dewi', 'Indra Gunawan',
        ];

        $paymentMethods = ['cash', 'cash', 'cash', 'transfer', 'debit_card'];

        for ($i = 0; $i < 30; $i++) {
            $user = $users->random();
            $itemCount = rand(1, 4);
            $selectedProducts = $products->random($itemCount);
            $totalAmount = 0;
            $items = [];

            foreach ($selectedProducts as $product) {
                $quantity = rand(1, 3);
                $unitPrice = $product->price;
                $subtotal = $quantity * $unitPrice;
                $totalAmount += $subtotal;

                $items[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ];
            }

            $paidAmount = $totalAmount + rand(0, 3) * 5000;

            $createdAt = now()->subDays(rand(0, 30))->setTime(rand(7, 21), rand(0, 59));

            $transaction = Transaction::create([
                'customer' => $customers[array_rand($customers)],
                'invoice_number' => 'INV-'.$createdAt->format('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'change_amount' => $paidAmount - $totalAmount,
                'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ($items as $item) {
                TransactionItem::create(array_merge($item, ['transaction_id' => $transaction->id]));
            }
        }
    }
}
