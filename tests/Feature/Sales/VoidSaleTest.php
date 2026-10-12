<?php

namespace Tests\Feature\Sales;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Actions\Sales\CreateSale;
use App\Actions\Sales\VoidSale;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Sales\VoidNotAllowed;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
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
 * Pembatalan transaksi terkontrol / void (§17.4, MVP §5.1 #10, FR-12).
 *
 * Yang diuji di sini adalah jaminan domain — bukan hanya tampilan: transaksi
 * tidak dihapus, alasan & izin wajib, stok dikembalikan lewat mutasi balik,
 * piutang dijaga, dan operasinya idempoten.
 */
class VoidSaleTest extends TestCase
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

        // Pemilik dapat membatalkan; viewer tidak punya izin `transaction.void`.
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
     * Penjualan tunai penuh lewat aksi domain — supaya void membalik catatan
     * nyata (item, stok, ledger, audit), bukan data buatan tes.
     */
    private function sale(Product $product, int $quantity = 2): Transaction
    {
        return app(CreateSale::class)->handle([
            'user_id' => $this->owner->id,
            'payment_method' => 'cash',
            'paid_minor' => $product->price * $quantity,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price_minor' => $product->price,
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
     * @return array<string, mixed>
     */
    private function void(Transaction $transaction, array $overrides = []): array
    {
        return app(VoidSale::class)->handle(array_merge([
            'transaction_id' => $transaction->id,
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'Salah input item',
        ], $overrides), $this->owner);
    }

    public function test_voiding_marks_the_sale_without_deleting_it_and_restores_stock(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        $this->assertSame(8, $product->refresh()->stock);

        $result = $this->void($sale);

        $this->assertFalse($result['already_void']);
        $this->assertTrue($result['stock_restored']);
        $this->assertSame(1, $result['restored_items']);

        // Tidak dihapus: baris dan itemnya tetap ada (§17.4).
        $sale->refresh();
        $this->assertNotNull($sale);
        $this->assertSame('void', $sale->payment_status);
        $this->assertTrue($sale->isVoided());
        $this->assertSame(Transaction::PAYMENT_STATUS_VOID, $sale->payment_status);
        $this->assertNotNull($sale->voided_at);
        $this->assertSame($this->owner->id, $sale->voided_by);
        $this->assertSame('Salah input item', $sale->void_reason);
        $this->assertTrue($sale->stock_restored);
        $this->assertSame(1, $sale->items()->count());

        // Stok kembali tepat sebesar yang dikurangi, lewat mutasi balik.
        $this->assertSame(10, $product->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'quantity_change' => 2,
            'before_stock' => 8,
            'after_stock' => 10,
            'reference_type' => 'sale_void',
            'reference_id' => $sale->id,
            'user_id' => $this->owner->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::SaleVoided->value,
            'entity_type' => 'sale',
            'entity_id' => $sale->uuid,
            'actor_id' => $this->owner->id,
        ]);

        $audit = AuditLog::where('action', AuditAction::SaleVoided->value)->firstOrFail();
        $this->assertSame('Salah input item', $audit->metadata['reason']);
        $this->assertSame('paid', $audit->metadata['previous_payment_status']);
        $this->assertTrue($audit->metadata['stock_restored']);
    }

    public function test_voiding_requires_a_reason(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product);

        try {
            $this->void($sale, ['reason' => '   ']);
            $this->fail('Pembatalan tanpa alasan seharusnya ditolak.');
        } catch (VoidNotAllowed $e) {
            $this->assertStringContainsString('alasan', $e->getMessage());
        }

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(8, $product->refresh()->stock);
    }

    public function test_an_actor_without_the_void_permission_is_rejected(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product);

        $this->assertFalse($this->viewer->can(Permission::VoidTransaction->value));

        $this->expectException(ActionForbidden::class);

        app(VoidSale::class)->handle([
            'transaction_id' => $sale->id,
            'user_id' => $this->viewer->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'Coba-coba',
        ], $this->viewer);
    }

    public function test_voiding_is_idempotent(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        $first = $this->void($sale);
        $second = $this->void($sale);

        $this->assertFalse($first['already_void']);
        $this->assertTrue($second['already_void']);
        $this->assertSame(0, $second['restored_items']);

        // Stok tidak dikembalikan dua kali & tidak ada mutasi/audit ganda.
        $this->assertSame(10, $product->refresh()->stock);
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_void')->count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::SaleVoided->value)->count());
    }

    public function test_stock_is_left_alone_when_the_return_policy_disables_restore(): void
    {
        config(['pos.sales.restore_stock_on_void' => false]);

        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        $result = $this->void($sale);

        $this->assertFalse($result['stock_restored']);
        $this->assertSame(0, $result['restored_items']);
        $this->assertSame(8, $product->refresh()->stock);

        $sale->refresh();
        $this->assertSame('void', $sale->payment_status);
        $this->assertFalse($sale->stock_restored);
        $this->assertSame(0, StockMovement::where('reference_type', 'sale_void')->count());
    }

    public function test_an_explicit_restore_flag_overrides_the_policy(): void
    {
        config(['pos.sales.restore_stock_on_void' => false]);

        $product = $this->product(stock: 10);
        $sale = $this->sale($product, quantity: 2);

        $this->void($sale, ['restore_stock' => true]);

        $this->assertSame(10, $product->refresh()->stock);
    }

    public function test_unknown_transaction_is_rejected(): void
    {
        $this->expectException(VoidNotAllowed::class);

        app(VoidSale::class)->handle([
            'transaction_id' => 999999,
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'Tidak ada',
        ], $this->owner);
    }

    public function test_an_unpaid_credit_sale_is_voided_and_the_customer_balance_reversed(): void
    {
        [$sale, $customer, $receivable] = $this->creditSale(totalMinor: 10000, paidMinor: 4000);

        $this->assertSame(6000.0, (float) $customer->refresh()->balance);
        $this->assertSame('open', $receivable->status);

        $result = $this->void($sale);

        $this->assertTrue($result['receivable_voided']);

        $receivable->refresh();
        $this->assertSame('void', $receivable->status);
        $this->assertSame(0, $receivable->remaining_minor);
        $this->assertSame(0.0, (float) $customer->refresh()->balance);
        $this->assertSame('void', $sale->refresh()->payment_status);
    }

    public function test_a_credit_sale_with_recorded_payments_cannot_be_voided(): void
    {
        [$sale, $customer, $receivable] = $this->creditSale(totalMinor: 10000, paidMinor: 4000);

        app(RecordReceivablePayment::class)->handle([
            'receivable_id' => $receivable->id,
            'amount_minor' => 1000,
            'user_id' => $this->owner->id,
        ]);

        try {
            $this->void($sale);
            $this->fail('Void atas piutang yang sudah dibayar seharusnya ditolak.');
        } catch (VoidNotAllowed $e) {
            $this->assertStringContainsString('piutang', $e->getMessage());
        }

        // Tidak ada yang berubah: pembayaran tidak boleh tersembunyi (FR-07).
        $this->assertSame('credit', $sale->refresh()->payment_status);
        $this->assertSame('open', $receivable->refresh()->status);
        $this->assertSame(5000, $receivable->remaining_minor);
        $this->assertSame(5000.0, (float) $customer->refresh()->balance);
        $this->assertSame(4, $receivable->transaction->items()->first()->product->refresh()->stock);
        $this->assertSame(0, StockMovement::where('reference_type', 'sale_void')->count());
    }

    public function test_a_voided_sale_disappears_from_financial_reports(): void
    {
        $product = $this->product(stock: 10, price: 15000);
        $sale = $this->sale($product, quantity: 2);

        $before = app(SalesReport::class)->range(now()->toDateString(), now()->toDateString());
        $this->assertSame(30000, $before['omzet_minor']);
        $this->assertSame(0, $before['dibatalkan_count']);

        $this->void($sale);

        $after = app(SalesReport::class)->range(now()->toDateString(), now()->toDateString());

        $this->assertSame(0, $after['omzet_minor']);
        $this->assertSame(0, $after['penerimaan_minor']);
        $this->assertSame(0, $after['transaksi_count']);
        $this->assertSame(1, $after['dibatalkan_count']);
        $this->assertSame(30000, $after['dibatalkan_minor']);
        $this->assertSame([], $after['produk_terlaris']);
        $this->assertCount(1, $after['riwayat']);
        $this->assertNotNull($after['riwayat'][0]['voided_at']);
        $this->assertSame('Salah input item', $after['riwayat'][0]['void_reason']);
    }
}
