<?php

namespace Tests\Feature\Reports;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Reports\SalesReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);
    }

    private function report(): SalesReport
    {
        return new SalesReport($this->tenant->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function txn(array $overrides = []): Transaction
    {
        // forceCreate: created_at boleh di-override meski tak ada di $fillable.
        return Transaction::forceCreate(array_merge([
            'invoice_number' => 'INV-'.Str::random(10),
            'customer_name' => 'Umum',
            // mirror kolom legacy (NOT NULL tanpa default)
            'total_amount' => 15000,
            'paid_amount' => 15000,
            'change_amount' => 0,
            'total_minor' => 15000,
            'subtotal_minor' => 15000,
            'discount_minor' => 0,
            'paid_minor' => 15000,
            'change_minor' => 0,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ], $overrides));
    }

    private function item(int $txnId, Product $product, int $quantity, int $subtotalMinor, int $discountMinor = 0): TransactionItem
    {
        return TransactionItem::create([
            'transaction_id' => $txnId,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price_minor' => $subtotalMinor + $discountMinor,
            'discount_minor' => $discountMinor,
            'subtotal_minor' => $subtotalMinor,
            'unit_price' => 0,
            'subtotal' => 0,
        ]);
    }

    public function test_daily_metrics_keep_omzet_cash_and_receivable_separate(): void
    {
        $product = Product::factory()->create(['price' => 15000]);
        $customer = Customer::create(['name' => 'Budi Santoso']);

        // Penjualan tunai lunas: 15.000.
        $this->txn(['payment_method' => 'cash']);

        // Penjualan kredit: total 15.000, bayar 10.000 → piutang 5.000.
        $credit = $this->txn(['payment_status' => 'credit', 'paid_minor' => 10000, 'payment_method' => 'cash']);

        // Penagihan piutang 2.000 di hari yang sama — TIDAK boleh mengubah metrik kasir.
        $receivable = Receivable::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'transaction_id' => $credit->id,
            'original_amount_minor' => 5000,
            'remaining_minor' => 5000,
            'status' => 'open',
        ]);
        app(RecordReceivablePayment::class)->handle([
            'receivable_id' => $receivable->id,
            'amount_minor' => 2000,
        ]);

        $report = $this->report()->daily();

        $this->assertSame(30000, $report['omzet_minor']);
        $this->assertSame(25000, $report['penerimaan_minor']);
        $this->assertSame(5000, $report['piutang_baru_minor']);
        $this->assertSame(2, $report['transaksi_count']);
        $this->assertSame(1, $report['transaksi_kredit_count']);
        $this->assertSame(15000, $report['rata_rata_transaksi_minor']);
    }

    public function test_range_filters_by_created_at(): void
    {
        $this->txn(['created_at' => now()->subDays(3)->setTime(12, 0)]);
        $this->txn();

        $today = $this->report()->range();
        $since = $this->report()->range(now()->subDays(4)->toDateString(), now()->toDateString());

        $this->assertSame(1, $today['transaksi_count']);
        $this->assertSame(15000, $today['omzet_minor']);
        $this->assertSame(2, $since['transaksi_count']);
        $this->assertSame(30000, $since['omzet_minor']);
    }

    public function test_discount_metrics_combine_transaction_and_item_discounts(): void
    {
        $product = Product::factory()->create(['price' => 10000]);

        $txn = $this->txn([
            'total_minor' => 9500,
            'subtotal_minor' => 10000,
            'discount_minor' => 500,
            'paid_minor' => 9500,
        ]);
        $this->item($txn->id, $product, 1, 9500, 500);

        $report = $this->report()->daily();

        $this->assertSame(1000, $report['diskon_minor']);
        $this->assertSame(1, $report['transaksi_berdiskon_count']);
    }

    public function test_payment_method_summary_groups_counts_and_amounts(): void
    {
        $this->txn(['payment_method' => 'cash']);
        $this->txn(['payment_method' => 'qris', 'total_minor' => 20000, 'subtotal_minor' => 20000, 'paid_minor' => 20000]);
        $this->txn(['payment_method' => 'cash', 'payment_status' => 'credit', 'paid_minor' => 5000]);

        $methods = collect($this->report()->daily()['metode_pembayaran'])->keyBy('payment_method');

        $this->assertSame(2, $methods['cash']['transaksi']);
        $this->assertSame(30000, $methods['cash']['omzet_minor']);
        $this->assertSame(20000, $methods['cash']['penerimaan_minor']);
        $this->assertSame(1, $methods['qris']['transaksi']);
        $this->assertSame(20000, $methods['qris']['penerimaan_minor']);
    }

    public function test_top_products_ordered_by_quantity_with_revenue(): void
    {
        $a = Product::factory()->create(['name' => 'Produk A', 'price' => 10000]);
        $b = Product::factory()->create(['name' => 'Produk B', 'price' => 5000]);

        $t1 = $this->txn();
        $this->item($t1->id, $a, 3, 30000);

        $t2 = $this->txn();
        $this->item($t2->id, $b, 1, 5000);

        $top = $this->report()->daily()['produk_terlaris'];

        $this->assertCount(2, $top);
        $this->assertSame($a->id, $top[0]['product_id']);
        $this->assertSame('Produk A', $top[0]['name']);
        $this->assertSame(3, $top[0]['quantity']);
        $this->assertSame(30000, $top[0]['omzet_minor']);
        $this->assertSame($b->id, $top[1]['product_id']);
        $this->assertSame(5000, $top[1]['omzet_minor']);
    }

    public function test_low_stock_lists_only_products_below_minimum(): void
    {
        Product::factory()->create(['name' => 'Menipis', 'price' => 10000, 'stock' => 2, 'min_stock' => 5, 'is_unlimited_stock' => false]);
        Product::factory()->create(['name' => 'Cukup', 'price' => 10000, 'stock' => 20, 'min_stock' => 5, 'is_unlimited_stock' => false]);
        Product::factory()->create(['name' => 'Tanpa Batas', 'price' => 10000, 'stock' => 0, 'min_stock' => 5, 'is_unlimited_stock' => true]);

        $low = $this->report()->daily()['stok_menipis'];

        $this->assertCount(1, $low);
        $this->assertSame('Menipis', $low[0]['name']);
        $this->assertSame(2, $low[0]['stock']);
        $this->assertSame(5, $low[0]['min_stock']);
    }

    public function test_riwayat_lists_transactions_newest_first(): void
    {
        $old = $this->txn(['created_at' => now()->subHours(2)]);
        $new = $this->txn(['created_at' => now()->subHour()]);

        $riwayat = $this->report()->daily()['riwayat'];

        $this->assertCount(2, $riwayat);
        $this->assertSame($new->id, $riwayat[0]['id']);
        $this->assertSame($old->id, $riwayat[1]['id']);
        $this->assertSame($new->invoice_number, $riwayat[0]['invoice_number']);
    }

    public function test_empty_day_returns_zero_metrics(): void
    {
        $report = $this->report()->daily();

        $this->assertSame(0, $report['omzet_minor']);
        $this->assertSame(0, $report['penerimaan_minor']);
        $this->assertSame(0, $report['piutang_baru_minor']);
        $this->assertSame(0, $report['transaksi_count']);
        $this->assertSame(0, $report['rata_rata_transaksi_minor']);
        $this->assertSame([], $report['metode_pembayaran']);
        $this->assertSame([], $report['produk_terlaris']);
        $this->assertSame([], $report['stok_menipis']);
    }

    public function test_metric_definitions_distinguish_omzet_cash_and_receivable(): void
    {
        $definitions = SalesReport::metricDefinitions();

        $this->assertArrayHasKey('omzet_minor', $definitions);
        $this->assertArrayHasKey('penerimaan_minor', $definitions);
        $this->assertArrayHasKey('piutang_baru_minor', $definitions);
        $this->assertNotSame($definitions['omzet_minor'], $definitions['penerimaan_minor']);
        $this->assertStringContainsString('TIDAK termasuk', $definitions['penerimaan_minor']);
    }

    public function test_report_respects_tenant_boundary(): void
    {
        $other = Tenant::factory()->create();
        app(TenantContext::class)->set($other);
        Transaction::create([
            'invoice_number' => 'INV-OTHER',
            'customer_name' => 'Tenant Lain',
            'total_amount' => 99999,
            'paid_amount' => 99999,
            'change_amount' => 0,
            'total_minor' => 99999,
            'subtotal_minor' => 99999,
            'paid_minor' => 99999,
            'change_minor' => 0,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);
        app(TenantContext::class)->set($this->tenant);

        $report = $this->report()->daily();

        $this->assertSame(0, $report['omzet_minor']);
        $this->assertSame(0, $report['transaksi_count']);
    }
}
