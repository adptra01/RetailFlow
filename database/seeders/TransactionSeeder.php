<?php

namespace Database\Seeders;

use App\Actions\Sales\CreateSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money\Money;
use Database\Seeders\Concerns\SeedsDemoTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transaksi demo lahir lewat jalur domain `CreateSale`, bukan penulisan
 * langsung ke kolom.
 *
 * Alasannya: sejak §17.1 nilai uang hanya benar sebagai integer minor unit,
 * dan satu-satunya penulis yang boleh mengisi total/minor, memotong stok, dan
 * membuka piutang adalah aksi domain. Seeder yang menulis kolom langsung
 * menghasilkan demo yang berbeda dari perilaku aplikasi — sebelumnya seluruh
 * `*_minor` bernilai 0 sehingga laporan dan piutang demo tampak kosong.
 */
class TransactionSeeder extends Seeder
{
    use SeedsDemoTenant;

    public function run(): void
    {
        $this->demoTenant();

        if (Transaction::count() > 0) {
            $this->command->warn('Transactions already exist, skipping TransactionSeeder.');

            return;
        }

        $users = User::all();
        $products = Product::with('units')->get();

        if ($products->isEmpty()) {
            $this->command->warn('Tidak ada produk, lewati TransactionSeeder.');

            return;
        }

        $customers = Customer::all();

        $customerNames = [
            'Ahmad Fauzi', 'Siti Nurhaliza', 'Bambang Wijaya', 'Dewi Lestari',
            'Rudi Hartono', 'Mega Putri', 'Agus Salim', 'Rina Marlina',
            'Doni Pratama', 'Indah Permata', 'Fajar Setiawan', 'Wulan Sari',
            'Rizky Ramadhan', 'Ratna Dewi', 'Indra Gunawan',
        ];

        $paymentMethods = ['cash', 'cash', 'cash', 'transfer', 'debit_card'];

        // Sisa stok yang boleh dipakai demo; produk stok 0 cukup dilewati
        // tanpa membuat transaksi gagal.
        $available = $products->mapWithKeys(fn (Product $product): array => [
            $product->getKey() => (int) $product->stock,
        ])->all();

        $created = 0;

        for ($i = 0; $i < 30; $i++) {
            $selectedProducts = $products->random(rand(1, min(4, $products->count())));
            $items = [];

            foreach ($selectedProducts as $product) {
                $baseUnit = $product->units->firstWhere('is_base', true) ?? $product->units->first();

                if (! $baseUnit) {
                    continue;
                }

                $quantity = rand(1, 3);

                if (($available[$product->getKey()] ?? 0) < $quantity) {
                    continue;
                }

                $available[$product->getKey()] -= $quantity;

                $unitPriceMinor = Money::fromMajor((float) ($baseUnit->price > 0 ? $baseUnit->price : $product->price))->minor();

                $items[] = [
                    'product_id' => $product->getKey(),
                    'product_unit_id' => $baseUnit->getKey(),
                    'unit_name' => $baseUnit->name,
                    'quantity' => $quantity,
                    'unit_price_minor' => $unitPriceMinor,
                ];
            }

            if ($items === []) {
                continue;
            }

            $totalMinor = collect($items)->sum(fn (array $item): int => $item['unit_price_minor'] * $item['quantity']);

            // Sekitar seperempat transaksi dibuat kredit supaya halaman
            // piutang & penagihan punya data (FR-07).
            $isCredit = $customers->isNotEmpty() && $i % 4 === 0;
            $customer = $isCredit ? $customers->random() : null;

            // Kelebihan bayar (kembalian) hanya sah untuk tunai — aturan yang
            // ditegakkan domain (FR-05), jadi metode ikut menyesuaikan.
            $overpayMinor = ! $isCredit && $i % 3 === 0 ? 5000 : 0;

            $paymentMethod = $isCredit || $overpayMinor > 0
                ? 'cash'
                : $paymentMethods[array_rand($paymentMethods)];

            $paidMinor = $isCredit
                ? ($i % 8 === 0 ? 0 : (int) round($totalMinor * 0.4))
                : $totalMinor + $overpayMinor;

            $payload = [
                'client_request_id' => (string) Str::uuid7(),
                'user_id' => $users->random()->getKey(),
                'customer_id' => $customer?->getKey(),
                'customer_name' => $customer?->name ?? $customerNames[array_rand($customerNames)],
                'payment_method' => $paymentMethod,
                'paid_minor' => $paidMinor,
                'items' => $items,
            ];

            if ($isCredit) {
                $payload['due_days'] = 14;
            }

            $transaction = (new CreateSale)->handle($payload);

            // Tanggal demo disebar ke 30 hari terakhir. Yang diubah hanya
            // metadata waktu, bukan nilai uang/stok.
            $createdAt = now()->subDays(rand(0, 30))->setTime(rand(7, 21), rand(0, 59));

            DB::table('transactions')
                ->where('id', $transaction->getKey())
                ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

            DB::table('transaction_items')
                ->where('transaction_id', $transaction->getKey())
                ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

            $created++;
        }

        $this->command->info("Created {$created} transactions (".Transaction::query()->where('payment_status', 'credit')->count().' kredit).');
    }
}
