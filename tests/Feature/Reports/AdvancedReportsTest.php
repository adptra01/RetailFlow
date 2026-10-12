<?php

namespace Tests\Feature\Reports;

use App\Actions\Reports\GenerateAgingReceivablesReport;
use App\Actions\Reports\GenerateCashFlowReport;
use App\Actions\Reports\GenerateMarginHistoryReport;
use App\Actions\Reports\GeneratePurchaseVsSalesReport;
use App\Actions\Reports\GenerateStockMovementReport;
use App\Actions\Reports\GenerateSupplierPaymentReport;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receivable;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdvancedReportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::factory()->create();
    }

    public function test_stock_movement_report_returns_movements_in_range(): void
    {
        $tenant = $this->tenant();
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        StockMovement::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'quantity_change' => -5,
            'before_stock' => 20,
            'after_stock' => 15,
            'reference_type' => 'App\Models\Transaction',
            'reference_id' => 1,
            'created_at' => now()->subDay(),
        ]);

        StockMovement::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'quantity_change' => 10,
            'before_stock' => 15,
            'after_stock' => 25,
            'reference_type' => 'App\Models\PurchaseOrder',
            'reference_id' => 2,
            'created_at' => now(),
        ]);

        $report = app(GenerateStockMovementReport::class)->handle([
            'tenant_id' => $tenant->id,
            'from_date' => now()->subDays(2)->format('Y-m-d'),
            'to_date' => now()->format('Y-m-d'),
        ]);

        $this->assertCount(2, $report);
        $this->assertEquals('in', $report->firstWhere('quantity_change', 10)['type']);
        $this->assertEquals('out', $report->firstWhere('quantity_change', -5)['type']);
        $this->assertEquals($product->name, $report->first()['product_name']);
        $this->assertEquals(25, $report->firstWhere('quantity_change', 10)['after_stock']);
    }

    public function test_stock_movement_report_respects_product_filter(): void
    {
        $tenant = $this->tenant();
        $productA = Product::factory()->create(['tenant_id' => $tenant->id]);
        $productB = Product::factory()->create(['tenant_id' => $tenant->id]);

        StockMovement::create([
            'tenant_id' => $tenant->id, 'product_id' => $productA->id, 'quantity_change' => 5,
            'reference_type' => 'App\Models\PurchaseOrder', 'reference_id' => 1,
        ]);
        StockMovement::create([
            'tenant_id' => $tenant->id, 'product_id' => $productB->id, 'quantity_change' => 5,
            'reference_type' => 'App\Models\PurchaseOrder', 'reference_id' => 2,
        ]);

        $report = app(GenerateStockMovementReport::class)->handle([
            'tenant_id' => $tenant->id,
            'product_id' => $productA->id,
        ]);

        $this->assertCount(1, $report);
        $this->assertEquals($productA->name, $report->first()['product_name']);
    }

    public function test_cash_flow_report_aggregates_inflows_and_outflows(): void
    {
        $tenant = $this->tenant();

        Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'total_minor' => 150000,
            'paid_minor' => 150000,
            'created_at' => now(),
        ]);

        PurchaseOrder::factory()->received()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => Supplier::factory()->create(['tenant_id' => $tenant->id])->id,
            'total_minor' => 200000,
            'created_at' => now(),
        ]);

        SupplierPayment::factory()->forTenant($tenant)->create([
            'supplier_id' => Supplier::factory()->create(['tenant_id' => $tenant->id])->id,
            'amount_minor' => 50000,
            'payment_date' => now(),
        ]);

        $report = app(GenerateCashFlowReport::class);
        $totals = $report->getTotals([
            'tenant_id' => $tenant->id,
            'from_date' => now()->subDay()->format('Y-m-d'),
            'to_date' => now()->addDay()->format('Y-m-d'),
        ]);

        $this->assertEquals(150000, $totals['total_in']);
        $this->assertEquals(250000, $totals['total_out']);
        $this->assertEquals(-100000, $totals['net']);
    }

    public function test_aging_report_buckets_receivables_by_due_date(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $current = Transaction::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'invoice_number' => 'INV-CURR']);
        $overdue = Transaction::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'invoice_number' => 'INV-BAD']);

        Receivable::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $current->id,
            'original_amount_minor' => 50000,
            'remaining_minor' => 50000,
            'status' => 'open',
            'due_at' => now()->addDays(7),
        ]);

        Receivable::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $overdue->id,
            'original_amount_minor' => 100000,
            'remaining_minor' => 100000,
            'status' => 'open',
            'due_at' => now()->subDays(95),
        ]);

        $report = app(GenerateAgingReceivablesReport::class);
        $summary = $report->getAgingSummary(['tenant_id' => $tenant->id]);

        $this->assertEquals(50000, $summary['Current']['total_minor']);
        $this->assertEquals(1, $summary['Current']['count']);
        $this->assertEquals(100000, $summary['>90 Days']['total_minor']);
        $this->assertEquals(1, $summary['>90 Days']['count']);
    }

    public function test_margin_history_calculates_revenue_cogs_and_margin(): void
    {
        $tenant = $this->tenant();
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'buy_price' => 6000,
            'price' => 10000,
        ]);

        $transaction = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'payment_status' => 'paid',
            'created_at' => now(),
        ]);

        TransactionItem::factory()->create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal' => 20000,
        ]);

        $report = app(GenerateMarginHistoryReport::class)->handle([
            'tenant_id' => $tenant->id,
            'from_date' => now()->subDay()->format('Y-m-d'),
            'to_date' => now()->addDay()->format('Y-m-d'),
            'group_by' => 'day',
        ]);

        $this->assertCount(1, $report);
        $row = $report->first();
        $this->assertEquals(20000, $row['revenue']);
        $this->assertEquals(12000, $row['cogs']);
        $this->assertEquals(8000, $row['margin']);
        $this->assertEquals(40.0, $row['margin_pct']);

        $productMargins = app(GenerateMarginHistoryReport::class)->getProductMargins([
            'tenant_id' => $tenant->id,
        ]);
        $this->assertEquals(2, $productMargins->first()['qty_sold']);
        $this->assertEquals(40.0, $productMargins->first()['margin_pct']);
    }

    public function test_margin_history_groups_weekly(): void
    {
        $tenant = $this->tenant();
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'buy_price' => 5000, 'price' => 10000]);

        Transaction::factory()->count(2)->create(['tenant_id' => $tenant->id, 'payment_status' => 'paid', 'created_at' => now()])
            ->each(function (Transaction $t) use ($product) {
                TransactionItem::factory()->create([
                    'tenant_id' => $t->tenant_id,
                    'transaction_id' => $t->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 10000,
                    'subtotal' => 10000,
                ]);
            });

        $report = app(GenerateMarginHistoryReport::class)->handle([
            'tenant_id' => $tenant->id,
            'from_date' => now()->startOfWeek()->format('Y-m-d'),
            'to_date' => now()->format('Y-m-d'),
            'group_by' => 'week',
        ]);

        $this->assertCount(1, $report);
        $this->assertEquals(20000, $report->first()['revenue']);
        $this->assertEquals(10000, $report->first()['cogs']);
        $this->assertEquals(50.0, $report->first()['margin_pct']);
    }

    public function test_supplier_payment_report_sums_total_paid(): void
    {
        $tenant = $this->tenant();
        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id, 'balance_minor' => 0]);

        SupplierPayment::factory()->forTenant($tenant)->create([
            'supplier_id' => $supplier->id,
            'amount_minor' => 75000,
            'payment_date' => now(),
        ]);
        SupplierPayment::factory()->forTenant($tenant)->create([
            'supplier_id' => $supplier->id,
            'amount_minor' => 25000,
            'payment_date' => now(),
        ]);

        $totals = app(GenerateSupplierPaymentReport::class)->getTotals([
            'tenant_id' => $tenant->id,
            'from_date' => now()->subDay()->format('Y-m-d'),
            'to_date' => now()->addDay()->format('Y-m-d'),
            'supplier_id' => $supplier->id,
        ]);

        $this->assertEquals(100000, $totals['total_paid']);
    }

    public function test_purchase_vs_sales_report_compares_quantities(): void
    {
        $tenant = $this->tenant();
        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'buy_price' => 6000, 'price' => 10000, 'stock' => 10]);

        $po = PurchaseOrder::factory()->received()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'created_at' => now(),
        ]);
        PurchaseOrderItem::factory()->create([
            'tenant_id' => $tenant->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price_minor' => 6000,
            'subtotal_minor' => 60000,
            'received_quantity' => 10,
        ]);

        $transaction = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'payment_status' => 'paid',
            'created_at' => now(),
        ]);
        TransactionItem::factory()->create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal' => 20000,
        ]);

        $rows = app(GeneratePurchaseVsSalesReport::class)->handle([
            'tenant_id' => $tenant->id,
            'from_date' => now()->subDay()->format('Y-m-d'),
            'to_date' => now()->addDay()->format('Y-m-d'),
        ]);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertEquals(2, $row['sales_qty']);
        $this->assertEquals(10, $row['purchase_qty']);
        $this->assertEquals(8, $row['qty_diff']);
        $this->assertEquals(20000, $row['sales_revenue']);
        $this->assertEquals('Overstock', $row['stock_status']);
    }
}
