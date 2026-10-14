<?php

namespace Database\Seeders;

use App\Models\Customer;
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
use Database\Seeders\Concerns\SeedsDemoTenant;
use Illuminate\Database\Seeder;

/**
 * Retur demo (§17.1): nilai kebenaran integer minor unit, sedangkan kolom
 * desimal legacy pada tabel penjualan diisi sebagai CERMIN MAJOR yang benar
 * (sebelumnya nilai minor ditulis apa adanya ke kolom major, sehingga retur
 * tampil 100× lebih besar di layar lama).
 *
 * Tabel pembelian sudah tidak punya kolom desimal legacy sama sekali, jadi
 * hanya kolom `*_minor` yang diisi.
 */
class ReturnSeeder extends Seeder
{
    use SeedsDemoTenant;

    public function run(): void
    {
        $tenant = $this->demoTenant();
        $users = User::all();

        if ($users->isEmpty()) {
            return;
        }

        // Sales Returns
        if (SalesReturn::count() === 0) {
            $transactions = Transaction::inRandomOrder()->take(5)->get();
            $userId = $users->random()->getKey();

            foreach ($transactions as $transaction) {
                $items = TransactionItem::where('transaction_id', $transaction->getKey())->get();
                if ($items->isEmpty()) {
                    continue;
                }

                $returnItems = $items->random(min(rand(1, 2), $items->count()));
                $totalMinor = 0;

                // `created_at` diisi langsung (bukan mass assignment) agar
                // tanggal demo tetap mengikuti tanggal transaksi.
                $return = new SalesReturn([
                    'return_number' => 'RTS-'.$transaction->created_at->format('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                    'transaction_id' => $transaction->getKey(),
                    'customer_id' => $transaction->customer_id ?? Customer::inRandomOrder()->first()?->getKey(),
                    'reason' => fake()->randomElement([
                        'Barang rusak', 'Kadaluarsa', 'Salah produk',
                        'Pelanggan komplain', 'Barang tidak sesuai',
                    ]),
                    'user_id' => $userId,
                    'tenant_id' => $tenant->getKey(),
                ]);
                $return->created_at = $transaction->created_at->addDays(rand(1, 3));
                $return->save();

                foreach ($returnItems as $item) {
                    $qty = rand(1, $item->quantity);
                    $sold = (int) $item->quantity;
                    // Minor adalah satu-satunya sumber kebenaran (§17.1).
                    $lineMinor = (int) $item->subtotal_minor;
                    // Nilai retur proporsional seperti domain (setelah diskon baris).
                    $subtotalMinor = $sold > 0 ? (int) round($lineMinor * $qty / $sold) : 0;
                    $unitPriceMinor = $qty > 0 ? (int) round($subtotalMinor / $qty) : 0;
                    $totalMinor += $subtotalMinor;

                    SalesReturnItem::create([
                        'sales_return_id' => $return->getKey(),
                        'transaction_item_id' => $item->getKey(),
                        'product_id' => $item->product_id,
                        'product_unit_id' => $item->product_unit_id ?? ProductUnit::where('product_id', $item->product_id)->value('id'),
                        'qty' => $qty,
                        'unit_price_minor' => $unitPriceMinor,
                        'discount_minor' => 0,
                        'subtotal_minor' => $subtotalMinor,
                        'reason' => $return->reason,
                        'tenant_id' => $tenant->getKey(),
                    ]);
                }

                // Data demo hanya untuk tampilan: stok TIDAK diubah (seperti
                // sebelumnya) dan seluruh nilai retur diperlakukan sebagai
                // refund kas. Jalur otoritatif tetap `CreateSalesReturn`.
                $return->update([
                    'subtotal_minor' => $totalMinor,
                    'total_minor' => $totalMinor,
                    'receivable_adjustment_minor' => 0,
                    'refund_minor' => $totalMinor,
                    'stock_restored' => false,
                ]);
            }
        }

        // Purchase Returns
        if (PurchaseReturn::count() === 0) {
            $purchaseOrders = PurchaseOrder::whereIn('status', ['received', 'partial'])
                ->inRandomOrder()->take(4)->get();

            foreach ($purchaseOrders as $po) {
                $items = PurchaseOrderItem::where('purchase_order_id', $po->getKey())->get();
                if ($items->isEmpty()) {
                    continue;
                }

                $returnItems = $items->random(min(rand(1, 2), $items->count()));
                $totalMinor = 0;

                $return = PurchaseReturn::create([
                    'return_number' => 'RTP-'.$po->created_at->format('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                    'purchase_order_id' => $po->getKey(),
                    'supplier_id' => $po->supplier_id ?? Supplier::inRandomOrder()->first()?->getKey(),
                    'subtotal_minor' => 0,
                    'discount_minor' => 0,
                    'total_minor' => 0,
                    'reason' => fake()->randomElement([
                        'Barang cacat', 'Kualitas tidak sesuai', 'Salah kirim',
                        'Jumlah tidak sesuai', 'Barang rusak',
                    ]),
                    'user_id' => $users->random()->getKey(),
                    'created_at' => ($po->created_at ?? now())->addDays(rand(1, 5)),
                ]);

                foreach ($returnItems as $item) {
                    $qty = rand(1, $item->quantity);
                    $unitPriceMinor = (int) $item->unit_price_minor;
                    $subtotalMinor = $qty * $unitPriceMinor;
                    $totalMinor += $subtotalMinor;

                    PurchaseReturnItem::create([
                        'purchase_return_id' => $return->getKey(),
                        'product_id' => $item->product_id,
                        'product_unit_id' => $item->product_unit_id,
                        'qty' => $qty,
                        'price_minor' => $unitPriceMinor,
                        'discount_minor' => 0,
                        'subtotal_minor' => $subtotalMinor,
                        'reason' => $return->reason,
                    ]);
                }

                if ($totalMinor > 0) {
                    $return->update([
                        'subtotal_minor' => $totalMinor,
                        'total_minor' => $totalMinor,
                    ]);
                }
            }
        }

        $this->command->info('Created returns: '.SalesReturn::count().' sales, '.PurchaseReturn::count().' purchases.');
    }
}
