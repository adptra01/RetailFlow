<?php

namespace Tests\Feature\Reports;

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
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdvancedReportsPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(Tenant $tenant): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('pemilik');

        return $user;
    }

    public function test_margin_report_page_renders(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->makeUser($tenant);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'buy_price' => 5000, 'price' => 10000]);
        $tx = Transaction::factory()->create(['tenant_id' => $tenant->id, 'payment_status' => 'paid', 'created_at' => now()]);
        TransactionItem::factory()->create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $tx->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_minor' => 10000,
            'subtotal_minor' => 10000,
        ]);

        $response = $this->actingAs($user)->get('/reports/margin');

        $response->assertStatus(200);
        $response->assertSee('Margin History Report');
    }

    public function test_stock_movement_report_page_renders(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->makeUser($tenant);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        StockMovement::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'quantity_change' => 5,
            'before_stock' => 0,
            'after_stock' => 5,
            'reference_type' => 'App\Models\PurchaseOrder',
            'reference_id' => 1,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/reports/stock-movement');

        $response->assertStatus(200);
        $response->assertSee('Stock Movement Report');
    }

    public function test_cash_flow_report_page_renders(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->makeUser($tenant);
        Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'total_minor' => 50000,
            'paid_minor' => 50000,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/reports/cash-flow');

        $response->assertStatus(200);
        $response->assertSee('Cash Flow Report');
    }

    public function test_aging_report_page_renders(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->makeUser($tenant);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $tx = Transaction::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);
        Receivable::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $tx->id,
            'original_amount_minor' => 25000,
            'remaining_minor' => 25000,
            'status' => 'open',
            'due_at' => now()->subDays(40),
        ]);

        $response = $this->actingAs($user)->get('/reports/aging');

        $response->assertStatus(200);
        $response->assertSee('Aging Receivables Report');
    }

    public function test_supplier_payments_report_page_renders(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->makeUser($tenant);
        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id, 'balance_minor' => 100000]);
        SupplierPayment::factory()->forTenant($tenant)->create([
            'supplier_id' => $supplier->id,
            'amount_minor' => 100000,
            'payment_date' => now(),
        ]);

        $response = $this->actingAs($user)->get('/reports/supplier-payments');

        $response->assertStatus(200);
        $response->assertSee('Supplier Payments Report');
    }

    public function test_purchase_vs_sales_report_page_renders(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->makeUser($tenant);
        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'buy_price' => 5000, 'price' => 10000, 'stock' => 10]);

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
            'unit_price_minor' => 5000,
            'subtotal_minor' => 50000,
            'received_quantity' => 10,
        ]);

        $tx = Transaction::factory()->create(['tenant_id' => $tenant->id, 'payment_status' => 'paid', 'created_at' => now()]);
        TransactionItem::factory()->create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $tx->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price_minor' => 10000,
            'subtotal_minor' => 20000,
        ]);

        $response = $this->actingAs($user)->get('/reports/purchase-vs-sales');

        $response->assertStatus(200);
        $response->assertSee('Purchase vs Sales Report');
    }
}
