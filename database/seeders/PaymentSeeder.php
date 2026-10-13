<?php

namespace Database\Seeders;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money\Money;
use Database\Seeders\Concerns\SeedsDemoTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Pembayaran demo (§17.1, FR-07).
 *
 * Penagihan piutang memakai aksi domain `RecordReceivablePayment` supaya sisa
 * saldo (`remaining_minor`) dan baris pembayaran konsisten — bukan ditulis
 * langsung. Pembayaran pemasok disimpan sebagai `amount_minor` (kolom desimal
 * legacy sudah tidak ada pada tabel pembelian).
 *
 * `customer_payments` (halaman cicilan lama) hanya punya kolom desimal
 * `amount`; isinya dihitung dari nilai minor transaksi sehingga tetap benar.
 */
class PaymentSeeder extends Seeder
{
    use SeedsDemoTenant;

    public function run(): void
    {
        $this->demoTenant();

        $users = User::all();
        if ($users->isEmpty()) {
            return;
        }

        // Penagihan piutang lewat jalur domain (FR-07).
        if (ReceivablePayment::count() === 0) {
            $receivables = Receivable::query()
                ->where('status', 'open')
                ->where('remaining_minor', '>', 0)
                ->get();

            $collected = 0;

            foreach ($receivables as $receivable) {
                // Sebagian piutang dibiarkan terbuka: demo butuh keduanya.
                if (rand(0, 1) === 0) {
                    continue;
                }

                $remainingMinor = (int) $receivable->remaining_minor;
                $amountMinor = rand(0, 1) === 0
                    ? $remainingMinor
                    : (int) round($remainingMinor * rand(3, 7) / 10);

                if ($amountMinor <= 0) {
                    continue;
                }

                (new RecordReceivablePayment)->handle([
                    'receivable_id' => $receivable->getKey(),
                    'amount_minor' => $amountMinor,
                    'payment_method' => fake()->randomElement(['cash', 'transfer']),
                    'user_id' => $users->random()->getKey(),
                    'client_request_id' => (string) Str::uuid7(),
                    'notes' => 'Pembayaran cicilan',
                    'paid_at' => ($receivable->created_at ?? now())->addDays(rand(1, 14)),
                ]);

                $collected++;
            }

            if ($collected > 0) {
                $this->command->info("Created {$collected} receivable payments.");
            }
        }

        // Cicilan pelanggan pada halaman lama (`customer_payments`).
        if (CustomerPayment::count() === 0) {
            $creditTransactions = Transaction::where('payment_status', 'credit')->get();
            $created = 0;

            foreach ($creditTransactions as $transaction) {
                $remainingMinor = (int) $transaction->total_minor - (int) $transaction->paid_minor;
                if ($remainingMinor <= 0) {
                    continue;
                }

                $amountMinor = rand(0, 1) === 0
                    ? $remainingMinor
                    : (int) round($remainingMinor * rand(5, 10) / 10, -3);

                if ($amountMinor <= 0) {
                    continue;
                }

                CustomerPayment::create([
                    'customer_id' => $transaction->customer_id ?? Customer::inRandomOrder()->first()?->getKey(),
                    'transaction_id' => $transaction->getKey(),
                    'amount' => (new Money($amountMinor))->major(),
                    'payment_date' => $transaction->created_at->addDays(rand(1, 14)),
                    'payment_method' => fake()->randomElement(['cash', 'transfer']),
                    'notes' => 'Pembayaran cicilan',
                    'user_id' => $users->random()->getKey(),
                ]);
                $created++;
            }

            $this->command->info("Created {$created} customer payments.");
        }

        // Pembayaran pemasok (minor unit).
        if (SupplierPayment::count() === 0) {
            $purchaseOrders = PurchaseOrder::whereIn('status', ['received', 'partial'])->get();
            $created = 0;

            foreach ($purchaseOrders as $po) {
                $totalMinor = (int) $po->total_minor;
                if ($totalMinor <= 0) {
                    continue;
                }

                $amountMinor = rand(0, 1) === 0
                    ? $totalMinor
                    : (int) round($totalMinor * rand(5, 10) / 10, -3);

                SupplierPayment::create([
                    'supplier_id' => $po->supplier_id ?? Supplier::inRandomOrder()->first()?->getKey(),
                    'purchase_order_id' => $po->getKey(),
                    'amount_minor' => $amountMinor,
                    'payment_date' => ($po->created_at ?? now())->addDays(rand(1, 14)),
                    'payment_method' => fake()->randomElement(['cash', 'transfer']),
                    'notes' => rand(0, 1) ? 'Pembayaran PO #'.$po->order_number : null,
                    'user_id' => $users->random()->getKey(),
                ]);
                $created++;
            }

            $this->command->info("Created {$created} supplier payments.");
        }
    }
}
