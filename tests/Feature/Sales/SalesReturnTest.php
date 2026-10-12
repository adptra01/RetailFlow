<?php

namespace Tests\Feature\Sales;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Actions\Reports\GenerateCashFlowReport;
use App\Actions\Sales\CreateSale;
use App\Actions\Sales\CreateSalesReturn;
use App\Actions\Sales\VoidSale;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Sales\ReturnNotAllowed;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Reports\SalesReport;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Retur penjualan terkontrol (FR-08 "Retur penjualan dengan alasan",
 * FR-09 "Retur dan pembatalan", §17.4).
 *
 * Yang diuji adalah jaminan domain: alasan & izin wajib, jumlah retur tidak
 * melebihi nota, stok hanya kembali sesuai kebijakan lewat mutasi yang dapat
 * ditelusuri, koreksi pembayaran tercatat (piutang lebih dahulu, sisanya
 * refund), dan operasinya idempoten.
 */
class SalesReturnTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Pemilik dapat melakukan retur; viewer tidak punya izin
        // `transaction.return`.
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->viewer->assignRole('viewer');
    }

    private function product(int $stock = 10, int $price = 15000): Product
    {
        return Product::factory()->create([
            'stock' => $stock,
            'price' => $price,
            'is_unlimited_stock' => false,
        ]);
    }

    /**
     * Penjualan tunai penuh lewat aksi domain — agar retur mengoreksi catatan
     * nyata (item, stok, piutang, audit), bukan data buatan tes.
     */
    private function sale(Product $product, int $quantity = 2): Transaction
    {
        return app(CreateSale::class)->handle([
            'user_id' => $this->owner->id,
            'payment_method' => 'cash',
            'paid_minor' => (int) $product->price * $quantity,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price_minor' => (int) $product->price,
                ],
            ],
        ]);
    }

    /**
     * Penjualan kredit: sebagian dibayar, sisanya menjadi piutang pelanggan.
     *
     * @return array{0: Transaction, 1: Customer, 2: Receivable}
     */
    private function creditSale(int $totalMinor = 10000, int $paidMinor = 4000): array
    {
        $product = $this->product(stock: 5, price: $totalMinor);
        $customer = Customer::create(['name' => 'Budi Santoso']);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $this->owner->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'payment_method' => 'cash',
            'paid_minor' => $paidMinor,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => $totalMinor],
            ],
        ]);

        return [$sale, $customer, Receivable::where('transaction_id', $sale->id)->firstOrFail()];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function retur(Transaction $transaction, array $items, array $overrides = [], ?User $actor = null): SalesReturn
    {
        return app(CreateSalesReturn::class)->handle(array_merge([
            'transaction_id' => $transaction->id,
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'Barang tidak sesuai pesanan',
            'items' => $items,
        ], $overrides), $actor ?? $this->owner);
    }

    /**
     * @return array{transaction_item_id: int, quantity: int}
     */
    private function line(Transaction $transaction, int $quantity): array
    {
        return [
            'transaction_item_id' => $transaction->items()->firstOrFail()->id,
            'quantity' => $quantity,
        ];
    }

    public function test_returning_items_creates_a_traced_document_and_restores_stock(): void
    {
        $product = $this->product(stock: 10, price: 15000);
        $sale = $this->sale($product, quantity: 2);

        $this->assertSame(8, $product->refresh()->stock);

        $return = $this->retur($sale, [$this->line($sale, 2)]);

        // Dokumen retur: nomor sendiri, alasan, nilai, kebijakan stok.
        $this->assertStringStartsWith('RTS-', $return->return_number);
        $this->assertSame($sale->id, $return->transaction_id);
        $this->assertSame('Barang tidak sesuai pesanan', $return->reason);
        $this->assertSame(30000, $return->total_minor);
        $this->assertSame(0, $return->discount_minor);
        $this->assertTrue($return->stock_restored);
        $this->assertSame($this->owner->id, $return->user_id);
        $this->assertSame($this->tenant->id, $return->tenant_id);
        $this->assertNotNull($return->uuid);
        $this->assertCount(1, $return->items);

        $item = $return->items->first();
        $this->assertSame(2, $item->qty);
        $this->assertSame(15000, $item->unit_price_minor);
        $this->assertSame(30000, $item->subtotal_minor);
        $this->assertSame($sale->items()->first()->id, $item->transaction_item_id);

        // Penjualan tunai penuh: tidak ada piutang, seluruh nilai dikembalikan
        // sebagai kas.
        $this->assertSame(0, $return->receivable_adjustment_minor);
        $this->assertSame(30000, $return->refund_minor);

        // Stok kembali lewat mutasi yang dapat ditelusuri (FR-08).
        $this->assertSame(10, $product->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'quantity_change' => 2,
            'before_stock' => 8,
            'after_stock' => 10,
            'reference_type' => 'sale_return',
            'reference_id' => $return->id,
            'user_id' => $this->owner->id,
        ]);

        // Jejak audit (FR-12).
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::SaleReturned->value,
            'entity_type' => 'sale_return',
            'entity_id' => $return->uuid,
            'actor_id' => $this->owner->id,
        ]);

        $audit = AuditLog::where('action', AuditAction::SaleReturned->value)->firstOrFail();
        $this->assertSame('Barang tidak sesuai pesanan', $audit->metadata['reason']);
        $this->assertSame($sale->invoice_number, $audit->metadata['invoice_number']);
        $this->assertSame(30000, $audit->metadata['total_minor']);
        $this->assertSame(30000, $audit->metadata['refund_minor']);
        $this->assertTrue($audit->metadata['stock_restored']);

        // Transaksi penjualan tidak diubah oleh retur — koreksinya dokumen baru.
        $this->assertSame('paid', $sale->refresh()->payment_status);
    }

    public function test_a_partial_return_is_valued_proportionally(): void
    {
        $product = $this->product(stock: 10, price: 15000);
        $sale = $this->sale($product, quantity: 2);

        $return = $this->retur($sale, [$this->line($sale, 1)]);

        $this->assertSame(15000, $return->total_minor);
        $this->assertSame(1, $return->items->first()->qty);
        $this->assertSame(9, $product->refresh()->stock);
    }

    public function test_a_reason_is_required(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product);

        try {
            $this->retur($sale, [$this->line($sale, 1)], ['reason' => '   ']);
            $this->fail('Retur tanpa alasan seharusnya ditolak.');
        } catch (ReturnNotAllowed $e) {
            $this->assertStringContainsString('alasan', $e->getMessage());
        }

        $this->assertSame(0, SalesReturn::count());
        $this->assertSame(8, $product->refresh()->stock);
    }

    public function test_an_actor_without_the_return_permission_is_rejected(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product);

        $this->assertFalse($this->viewer->can(Permission::ReturnTransaction->value));

        $this->expectException(ActionForbidden::class);

        $this->retur($sale, [$this->line($sale, 1)], [], $this->viewer);
    }

    public function test_a_return_cannot_exceed_the_sold_quantity(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        try {
            $this->retur($sale, [$this->line($sale, 3)]);
            $this->fail('Retur melebihi jumlah terjual seharusnya ditolak.');
        } catch (ReturnNotAllowed $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        $this->assertSame(0, SalesReturn::count());
        $this->assertSame(8, $product->refresh()->stock);
    }

    public function test_repeated_returns_cannot_exceed_the_sold_quantity(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        $first = $this->retur($sale, [$this->line($sale, 1)]);
        $this->assertSame(1, $first->items->first()->qty);

        // Sisa yang masih dapat diretur = 1.
        try {
            $this->retur($sale, [$this->line($sale, 2)]);
            $this->fail('Retur berulang melebihi nota seharusnya ditolak.');
        } catch (ReturnNotAllowed $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        $second = $this->retur($sale, [$this->line($sale, 1)]);
        $this->assertSame(1, $second->items->first()->qty);

        // Stok hanya kembali sebesar yang benar-benar diretur.
        $this->assertSame(10, $product->refresh()->stock);
        $this->assertSame(2, SalesReturn::count());
    }

    public function test_an_item_from_another_sale_is_rejected(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 1);
        $otherSale = $this->sale($this->product(stock: 10), quantity: 1);

        try {
            $this->retur($sale, [$this->line($otherSale, 1)]);
            $this->fail('Baris dari transaksi lain seharusnya ditolak.');
        } catch (ReturnNotAllowed $e) {
            $this->assertStringContainsString('tidak termasuk', $e->getMessage());
        }

        $this->assertSame(0, SalesReturn::count());
    }

    public function test_a_voided_sale_cannot_be_returned(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        app(VoidSale::class)->handle([
            'transaction_id' => $sale->id,
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'Salah input item',
        ], $this->owner);

        try {
            $this->retur($sale, [$this->line($sale, 1)]);
            $this->fail('Retur atas transaksi batal seharusnya ditolak.');
        } catch (ReturnNotAllowed $e) {
            $this->assertStringContainsString('dibatalkan', $e->getMessage());
        }

        $this->assertSame(0, SalesReturn::count());
    }

    public function test_an_unknown_sale_is_rejected(): void
    {
        $this->expectException(ReturnNotAllowed::class);

        app(CreateSalesReturn::class)->handle([
            'transaction_id' => 999999,
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'Tidak ada',
            'items' => [['transaction_item_id' => 1, 'quantity' => 1]],
        ], $this->owner);
    }

    public function test_a_return_without_items_is_rejected(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product);

        $this->expectException(ReturnNotAllowed::class);

        $this->retur($sale, []);
    }

    public function test_replaying_the_same_client_request_id_returns_the_same_document_once(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        $clientRequestId = Str::uuid7()->toString();

        $first = $this->retur($sale, [$this->line($sale, 2)], ['client_request_id' => $clientRequestId]);
        $second = $this->retur($sale, [$this->line($sale, 2)], ['client_request_id' => $clientRequestId]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->return_number, $second->return_number);
        $this->assertSame(1, SalesReturn::count());

        // Stok dikembalikan sekali, bukan dua kali (§17.1).
        $this->assertSame(10, $product->refresh()->stock);
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_return')->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::SaleReturned->value)->count());
    }

    public function test_stock_is_left_alone_when_the_return_policy_disables_restore(): void
    {
        config(['pos.sales.restore_stock_on_return' => false]);

        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        $return = $this->retur($sale, [$this->line($sale, 2)]);

        $this->assertFalse($return->stock_restored);
        $this->assertSame(8, $product->refresh()->stock);
        $this->assertSame(0, StockMovement::where('reference_type', 'sale_return')->count());

        // Nilai & uang tetap dikoreksi: kebijakan hanya menyangkut stok.
        $this->assertSame(30000, $return->total_minor);
        $this->assertSame(30000, $return->refund_minor);
    }

    public function test_an_explicit_restore_flag_overrides_the_policy(): void
    {
        config(['pos.sales.restore_stock_on_return' => false]);

        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        $this->retur($sale, [$this->line($sale, 2)], ['restore_stock' => true]);

        $this->assertSame(10, $product->refresh()->stock);
    }

    public function test_unlimited_stock_products_create_no_movement(): void
    {
        $product = Product::factory()->create([
            'stock' => 0,
            'price' => 15000,
            'is_unlimited_stock' => true,
        ]);

        $sale = $this->sale($product, quantity: 2);
        $return = $this->retur($sale, [$this->line($sale, 2)]);

        $this->assertTrue($return->stock_restored);
        $this->assertSame(0, StockMovement::where('reference_type', 'sale_return')->count());
    }

    public function test_a_credit_return_cancels_the_outstanding_receivable_before_refunding(): void
    {
        [$sale, $customer, $receivable] = $this->creditSale(totalMinor: 10000, paidMinor: 4000);

        $this->assertSame(6000, $receivable->remaining_minor);
        $this->assertSame(6000.0, (float) $customer->refresh()->balance);

        $return = $this->retur($sale, [$this->line($sale, 1)]);

        // Nilai retur 10000: 6000 membatalkan sisa piutang, 4000 kembali ke kas.
        $this->assertSame(10000, $return->total_minor);
        $this->assertSame(6000, $return->receivable_adjustment_minor);
        $this->assertSame(4000, $return->refund_minor);

        $receivable->refresh();
        $this->assertSame(0, $receivable->remaining_minor);
        $this->assertSame(0, $receivable->original_amount_minor);
        $this->assertSame('closed', $receivable->status);
        $this->assertSame(0.0, (float) $customer->refresh()->balance);
    }

    public function test_a_return_on_a_fully_paid_credit_sale_is_refunded_in_full(): void
    {
        [$sale, $customer, $receivable] = $this->creditSale(totalMinor: 10000, paidMinor: 4000);

        app(RecordReceivablePayment::class)->handle([
            'receivable_id' => $receivable->id,
            'amount_minor' => 6000,
            'user_id' => $this->owner->id,
        ]);

        $this->assertSame('closed', $receivable->refresh()->status);

        $return = $this->retur($sale, [$this->line($sale, 1)]);

        $this->assertSame(0, $return->receivable_adjustment_minor);
        $this->assertSame(10000, $return->refund_minor);
        $this->assertSame(0.0, (float) $customer->refresh()->balance);
    }

    public function test_reports_separate_returns_from_sales_and_cash_flow(): void
    {
        $product = $this->product(stock: 10, price: 15000);
        $sale = $this->sale($product, quantity: 2);

        $this->retur($sale, [$this->line($sale, 2)]);

        $today = now()->toDateString();

        // FR-09: retur adalah metrik tersendiri, bukan pengurang omzet.
        $report = app(SalesReport::class)->range($today, $today);
        $this->assertSame(30000, $report['omzet_minor']);
        $this->assertSame(1, $report['retur_count']);
        $this->assertSame(30000, $report['retur_minor']);
        $this->assertSame(30000, $report['retur_refund_minor']);

        // §17.4: uang yang benar-benar dikembalikan tercatat sebagai kas keluar.
        $cashFlow = app(GenerateCashFlowReport::class)->handle([
            'tenant_id' => $this->tenant->id,
            'from_date' => $today,
            'to_date' => $today,
        ])->where('category', 'retur_penjualan');

        $this->assertCount(1, $cashFlow);
        $this->assertSame('out', $cashFlow->first()['type']);
        $this->assertSame(30000, $cashFlow->first()['amount_minor']);
        $this->assertSame($sale->id, SalesReturn::firstOrFail()->transaction_id);
    }

    public function test_a_credit_return_does_not_record_a_cash_outflow_for_the_receivable_part(): void
    {
        [$sale] = $this->creditSale(totalMinor: 10000, paidMinor: 4000);

        $this->retur($sale, [$this->line($sale, 1)]);

        $today = now()->toDateString();

        $cashFlow = app(GenerateCashFlowReport::class)->handle([
            'tenant_id' => $this->tenant->id,
            'from_date' => $today,
            'to_date' => $today,
        ])->where('category', 'retur_penjualan');

        // Hanya 4000 yang menjadi kas keluar; 6000 hanya koreksi piutang.
        $this->assertCount(1, $cashFlow);
        $this->assertSame(4000, $cashFlow->first()['amount_minor']);
    }

    public function test_a_device_return_is_queued_for_sync(): void
    {
        $product = $this->product(stock: 10, price: 15000);
        $sale = $this->sale($product, quantity: 2);
        $device = Device::factory()->create(['tenant_id' => $this->tenant->id]);

        $return = $this->retur($sale, [$this->line($sale, 2)], [
            'device_id' => $device->id,
            'client_request_id' => 'req-123',
        ]);

        $this->assertSame($device->id, $return->device_id);

        $this->assertDatabaseHas('sync_outbox', [
            'device_id' => $device->id,
            'entity_type' => 'sale_return',
            'entity_id' => $return->uuid,
            'operation_type' => 'create',
            'operation_id' => 'req-123',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_a_server_side_return_is_not_queued_for_sync(): void
    {
        $product = $this->product(stock: 10, price: 15000);
        $sale = $this->sale($product, quantity: 2);

        $this->retur($sale, [$this->line($sale, 2)]);

        $this->assertDatabaseMissing('sync_outbox', ['entity_type' => 'sale_return']);
    }
}
