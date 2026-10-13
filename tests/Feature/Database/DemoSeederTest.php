<?php

namespace Tests\Feature\Database;

use App\Models\Tenant;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\BrandSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Data demo (`php artisan db:seed`) harus memenuhi kontrak yang sama dengan
 * jalur aplikasi: ber-tenant (§12) dan nominal sebagai integer minor unit
 * (§17.1). Sebelumnya seluruh baris demo lahir dengan `tenant_id` NULL (tidak
 * akan pernah terlihat di UI) dan `*_minor` bernilai 0, sementara seeder
 * pembelian gagal di tengah jalan karena menulis kolom desimal yang sudah
 * dihapus.
 */
class DemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @var list<string>
     */
    private array $tenantTables = [
        'users', 'products', 'categories', 'customers', 'suppliers',
        'transactions', 'transaction_items', 'purchase_orders', 'purchase_order_items',
        'receivables', 'receivable_payments', 'sales_returns', 'sales_return_items',
        'purchase_returns', 'purchase_return_items', 'supplier_payments',
    ];

    private function seedDemo(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Guard & TenantContext adalah singleton: lepaskan ikatan seeder agar
        // tes berikutnya tidak mewarisi tenant dari proses seeding.
        app(TenantContext::class)->set(null);
    }

    public function test_database_seeder_populates_the_demo_store(): void
    {
        $this->seedDemo();

        $this->assertGreaterThan(0, DB::table('products')->count());
        $this->assertGreaterThan(0, DB::table('customers')->count());
        $this->assertGreaterThan(0, DB::table('transactions')->count());
        $this->assertGreaterThan(0, DB::table('purchase_orders')->count());
        $this->assertGreaterThan(0, DB::table('sales_returns')->count());
        $this->assertGreaterThan(0, DB::table('purchase_returns')->count());
    }

    public function test_every_demo_row_belongs_to_the_default_tenant(): void
    {
        $this->seedDemo();

        $tenantId = Tenant::query()->orderBy('id')->value('id');

        $this->assertNotNull($tenantId);

        foreach ($this->tenantTables as $table) {
            $orphans = DB::table($table)
                ->whereNull('tenant_id')
                ->orWhere('tenant_id', '!=', $tenantId)
                ->count();

            $this->assertSame(0, $orphans, "Tabel {$table} memuat baris demo tanpa tenant.");
        }
    }

    public function test_sales_demo_money_is_stored_in_minor_units(): void
    {
        $this->seedDemo();

        $transactions = DB::table('transactions')->get();

        $this->assertGreaterThan(0, $transactions->count());

        foreach ($transactions as $transaction) {
            // Kolom desimal legacy hanya cermin: nilai kebenarannya wajib sama
            // dengan kolom minor unit ketika dikonversi (§17.1).
            $this->assertGreaterThan(0, (int) $transaction->total_minor);
            $this->assertSame(
                Money::fromMajor((float) $transaction->total_amount)->minor(),
                (int) $transaction->total_minor,
            );
            $this->assertSame(
                Money::fromMajor((float) $transaction->paid_amount)->minor(),
                (int) $transaction->paid_minor,
            );
            $this->assertSame(
                Money::fromMajor((float) $transaction->change_amount)->minor(),
                (int) $transaction->change_minor,
            );
        }

        foreach (DB::table('transaction_items')->get() as $item) {
            $this->assertGreaterThan(0, (int) $item->unit_price_minor);
            $this->assertSame(
                Money::fromMajor((float) $item->unit_price)->minor(),
                (int) $item->unit_price_minor,
            );
        }

        // Piutang demo lahir dari penjualan kredit, bukan ditulis manual.
        $this->assertGreaterThan(0, DB::table('transactions')->where('payment_status', 'credit')->count());
        $this->assertGreaterThan(0, DB::table('receivables')->where('remaining_minor', '>', 0)->count());
    }

    public function test_receivable_balances_match_the_recorded_payments(): void
    {
        $this->seedDemo();

        $inconsistent = DB::table('receivables as r')
            ->whereRaw(
                'r.remaining_minor <> r.original_amount_minor - COALESCE((select sum(p.amount_minor) from receivable_payments p where p.receivable_id = r.id and p.reversed_at is null), 0)',
            )
            ->count();

        $this->assertSame(0, $inconsistent, 'Saldo piutang demo tidak cocok dengan catatan pembayarannya.');
    }

    public function test_purchase_demo_money_is_stored_in_minor_units(): void
    {
        $this->seedDemo();

        $this->assertSame(0, DB::table('purchase_orders')->where('total_minor', 0)->count());
        $this->assertSame(0, DB::table('purchase_order_items')->where('unit_price_minor', 0)->count());
        $this->assertSame(0, DB::table('purchase_return_items')->where('price_minor', 0)->count());
        $this->assertSame(0, DB::table('purchase_returns')->where('total_minor', 0)->count());
        $this->assertSame(0, DB::table('supplier_payments')->where('amount_minor', 0)->count());
    }

    public function test_sales_return_demo_money_mirrors_minor_units(): void
    {
        $this->seedDemo();

        foreach (DB::table('sales_returns')->get() as $return) {
            $this->assertGreaterThan(0, (int) $return->total_minor);
            $this->assertSame(
                (int) $return->total_minor,
                Money::fromMajor((float) $return->total)->minor(),
            );
        }

        foreach (DB::table('sales_return_items')->get() as $item) {
            $this->assertSame(
                (int) $item->unit_price_minor,
                Money::fromMajor((float) $item->price)->minor(),
            );
            $this->assertSame(
                (int) $item->subtotal_minor,
                Money::fromMajor((float) $item->subtotal)->minor(),
            );
        }
    }

    public function test_seeders_run_individually_still_bind_the_default_tenant(): void
    {
        // Tanpa DatabaseSeeder: `php artisan db:seed --class=ProductSeeder`
        // tetap wajib menghasilkan baris ber-tenant, bukan tenant_id NULL.
        $this->seed([
            TenantSeeder::class,
            BrandSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
        ]);

        app(TenantContext::class)->set(null);

        $tenantId = Tenant::query()->orderBy('id')->value('id');

        $this->assertGreaterThan(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('products')->whereNull('tenant_id')->count());
        $this->assertSame(
            DB::table('products')->count(),
            DB::table('products')->where('tenant_id', $tenantId)->count(),
        );
    }

    public function test_database_seeder_is_idempotent(): void
    {
        $this->seedDemo();

        $before = collect($this->tenantTables)
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

        $this->seedDemo();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Seeder menjalankan {$table} dua kali.");
        }
    }
}
