<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Purchases\CreatePurchaseOrder;
use App\Actions\Purchases\CreatePurchaseReturn;
use App\Actions\Purchases\ReceiveGoods;
use App\Actions\Purchases\RecordSupplierPayment;
use App\Actions\Receivables\RecordReceivablePayment;
use App\Actions\Sales\CreateSale;
use App\Actions\Sales\CreateSalesReturn;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Kunci idempotensi adalah milik tenant, bukan global (§12).
 *
 * `client_request_id` dibuat perangkat, sehingga dua tenant bisa kebetulan
 * memakai kunci yang sama. Bila pencarian idempotensi tidak dibatasi tenant,
 * operasi tenant B dapat mengembalikan record tenant A: data tenant lain
 * bocor lewat jawaban, dan operasi sah tenant B tidak pernah dijalankan.
 *
 * Setiap aksi domain diuji dengan pola yang sama: kunci K dipakai di tenant
 * A, lalu kunci K yang SAMA dipakai di tenant B — hasilnya wajib record milik
 * tenant B.
 */
class IdempotencyTenantScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private User $ownerA;

    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->a = Tenant::factory()->create();
        $this->b = Tenant::factory()->create();

        $this->ownerA = $this->owner($this->a);
        $this->ownerB = $this->owner($this->b);
    }

    private function owner(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('owner');

        return $user;
    }

    /**
     * Menjalankan aksi sebagai tenant tertentu, seperti permintaan sungguhan.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asTenant(Tenant $tenant, callable $callback)
    {
        app(TenantContext::class)->set($tenant);

        return $callback();
    }

    private function product(int $stock = 20, int $price = 5000): Product
    {
        return Product::factory()->create([
            'stock' => $stock,
            'price' => $price,
            'is_unlimited_stock' => false,
        ]);
    }

    /**
     * Penjualan kredit sebagian: sisanya menjadi piutang pelanggan.
     *
     * @return array{0: Transaction, 1: Receivable}
     */
    private function creditSale(Tenant $tenant, User $owner, Product $product): array
    {
        $customer = Customer::create(['name' => 'Pelanggan '.$tenant->id]);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $owner->getKey(),
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'payment_method' => 'cash',
            'paid_minor' => 2000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->getKey(), 'quantity' => 1, 'unit_price_minor' => 5000],
            ],
        ]);

        return [$sale, Receivable::query()->where('transaction_id', $sale->getKey())->firstOrFail()];
    }

    private function purchaseOrder(Tenant $tenant, User $owner, Supplier $supplier, Product $product): PurchaseOrder
    {
        return app(CreatePurchaseOrder::class)->handle([
            'supplier_id' => $supplier->getKey(),
            'user_id' => $owner->getKey(),
            'tenant_id' => $tenant->id,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                [
                    'product_id' => $product->getKey(),
                    'quantity' => 5,
                    'unit_price_minor' => 3000,
                ],
            ],
        ]);
    }

    public function test_receivable_payment_idempotency_keys_are_scoped_per_tenant(): void
    {
        $key = Str::uuid7()->toString();

        [, $receivableA] = $this->asTenant($this->a, fn () => $this->creditSale($this->a, $this->ownerA, $this->product()));

        $paymentA = $this->asTenant($this->a, fn () => app(RecordReceivablePayment::class)->handle([
            'receivable_id' => $receivableA->getKey(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
            'user_id' => $this->ownerA->getKey(),
            'client_request_id' => $key,
            'tenant_id' => $this->a->id,
        ]));

        $this->assertSame($this->a->id, $paymentA->tenant_id);

        [, $receivableB] = $this->asTenant($this->b, fn () => $this->creditSale($this->b, $this->ownerB, $this->product()));

        $paymentB = $this->asTenant($this->b, fn () => app(RecordReceivablePayment::class)->handle([
            'receivable_id' => $receivableB->getKey(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
            'user_id' => $this->ownerB->getKey(),
            'client_request_id' => $key,
            'tenant_id' => $this->b->id,
        ]));

        $this->assertSame($this->b->id, $paymentB->tenant_id);
        $this->assertNotSame($paymentA->getKey(), $paymentB->getKey());
        $this->assertSame(
            1000,
            (int) ReceivablePayment::query()->whereKey($paymentB->getKey())->value('amount_minor'),
        );
        $this->assertSame(
            2000,
            (int) Receivable::query()->whereKey($receivableB->getKey())->value('remaining_minor'),
        );
    }

    public function test_sales_return_idempotency_keys_are_scoped_per_tenant(): void
    {
        $key = Str::uuid7()->toString();

        $returnA = $this->asTenant($this->a, function () use ($key): SalesReturn {
            $product = $this->product();
            $sale = app(CreateSale::class)->handle([
                'user_id' => $this->ownerA->getKey(),
                'tenant_id' => $this->a->id,
                'payment_method' => 'cash',
                'paid_minor' => 10000,
                'client_request_id' => Str::uuid7()->toString(),
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price_minor' => 5000]],
            ]);

            return app(CreateSalesReturn::class)->handle([
                'transaction_id' => $sale->getKey(),
                'user_id' => $this->ownerA->getKey(),
                'tenant_id' => $this->a->id,
                'reason' => 'Barang tidak sesuai',
                'client_request_id' => $key,
                'items' => [['transaction_item_id' => $sale->items()->firstOrFail()->getKey(), 'quantity' => 1]],
            ], $this->ownerA);
        });

        $this->assertSame($this->a->id, $returnA->tenant_id);

        $returnB = $this->asTenant($this->b, function () use ($key): SalesReturn {
            $product = $this->product();
            $sale = app(CreateSale::class)->handle([
                'user_id' => $this->ownerB->getKey(),
                'tenant_id' => $this->b->id,
                'payment_method' => 'cash',
                'paid_minor' => 10000,
                'client_request_id' => Str::uuid7()->toString(),
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price_minor' => 5000]],
            ]);

            return app(CreateSalesReturn::class)->handle([
                'transaction_id' => $sale->getKey(),
                'user_id' => $this->ownerB->getKey(),
                'tenant_id' => $this->b->id,
                'reason' => 'Barang tidak sesuai',
                'client_request_id' => $key,
                'items' => [['transaction_item_id' => $sale->items()->firstOrFail()->getKey(), 'quantity' => 1]],
            ], $this->ownerB);
        });

        $this->assertSame($this->b->id, $returnB->tenant_id);
        $this->assertNotSame($returnA->getKey(), $returnB->getKey());
        $this->assertSame(1, $returnB->items()->count());
    }

    public function test_purchase_order_idempotency_keys_are_scoped_per_tenant(): void
    {
        $key = Str::uuid7()->toString();

        $orderA = $this->asTenant($this->a, fn () => app(CreatePurchaseOrder::class)->handle([
            'supplier_id' => Supplier::factory()->forTenant($this->a)->create()->getKey(),
            'user_id' => $this->ownerA->getKey(),
            'tenant_id' => $this->a->id,
            'client_request_id' => $key,
            'items' => [['product_id' => $this->product()->getKey(), 'quantity' => 5, 'unit_price_minor' => 3000]],
        ]));

        $this->assertSame($this->a->id, $orderA->tenant_id);

        $orderB = $this->asTenant($this->b, fn () => app(CreatePurchaseOrder::class)->handle([
            'supplier_id' => Supplier::factory()->forTenant($this->b)->create()->getKey(),
            'user_id' => $this->ownerB->getKey(),
            'tenant_id' => $this->b->id,
            'client_request_id' => $key,
            'items' => [['product_id' => $this->product()->getKey(), 'quantity' => 5, 'unit_price_minor' => 3000]],
        ]));

        $this->assertSame($this->b->id, $orderB->tenant_id);
        $this->assertNotSame($orderA->getKey(), $orderB->getKey());
        $this->assertNotSame($orderA->order_number, $orderB->order_number);
    }

    public function test_goods_receipt_idempotency_keys_are_scoped_per_tenant(): void
    {
        $key = Str::uuid7()->toString();

        $receiptA = $this->asTenant($this->a, function () use ($key): GoodsReceipt {
            $order = $this->purchaseOrder(
                $this->a,
                $this->ownerA,
                Supplier::factory()->forTenant($this->a)->create(),
                $this->product(),
            );

            return app(ReceiveGoods::class)->handle([
                'purchase_order_id' => $order->getKey(),
                'user_id' => $this->ownerA->getKey(),
                'tenant_id' => $this->a->id,
                'client_request_id' => $key,
                'items' => [[
                    'purchase_order_item_id' => $order->items()->firstOrFail()->getKey(),
                    'quantity' => 5,
                ]],
            ]);
        });

        $this->assertSame($this->a->id, $receiptA->tenant_id);

        $receiptB = $this->asTenant($this->b, function () use ($key): GoodsReceipt {
            $order = $this->purchaseOrder(
                $this->b,
                $this->ownerB,
                Supplier::factory()->forTenant($this->b)->create(),
                $this->product(),
            );

            return app(ReceiveGoods::class)->handle([
                'purchase_order_id' => $order->getKey(),
                'user_id' => $this->ownerB->getKey(),
                'tenant_id' => $this->b->id,
                'client_request_id' => $key,
                'items' => [[
                    'purchase_order_item_id' => $order->items()->firstOrFail()->getKey(),
                    'quantity' => 5,
                ]],
            ]);
        });

        $this->assertSame($this->b->id, $receiptB->tenant_id);
        $this->assertNotSame($receiptA->getKey(), $receiptB->getKey());
    }

    public function test_supplier_payment_idempotency_keys_are_scoped_per_tenant(): void
    {
        $key = Str::uuid7()->toString();

        $paymentA = $this->asTenant($this->a, fn () => app(RecordSupplierPayment::class)->handle([
            'supplier_id' => Supplier::factory()->forTenant($this->a)->create()->getKey(),
            'user_id' => $this->ownerA->getKey(),
            'tenant_id' => $this->a->id,
            'amount_minor' => 2500,
            'payment_method' => 'cash',
            'client_request_id' => $key,
        ]));

        $this->assertSame($this->a->id, $paymentA->tenant_id);

        $paymentB = $this->asTenant($this->b, fn () => app(RecordSupplierPayment::class)->handle([
            'supplier_id' => Supplier::factory()->forTenant($this->b)->create()->getKey(),
            'user_id' => $this->ownerB->getKey(),
            'tenant_id' => $this->b->id,
            'amount_minor' => 2500,
            'payment_method' => 'cash',
            'client_request_id' => $key,
        ]));

        $this->assertSame($this->b->id, $paymentB->tenant_id);
        $this->assertNotSame($paymentA->getKey(), $paymentB->getKey());
        $this->assertSame(
            1,
            SupplierPayment::query()->where('tenant_id', $this->b->id)->count(),
        );
    }

    public function test_purchase_return_idempotency_keys_are_scoped_per_tenant(): void
    {
        $key = Str::uuid7()->toString();

        $returnA = $this->asTenant($this->a, function () use ($key): PurchaseReturn {
            $product = $this->product();
            $supplier = Supplier::factory()->forTenant($this->a)->create();
            $order = $this->purchaseOrder($this->a, $this->ownerA, $supplier, $product);

            app(ReceiveGoods::class)->handle([
                'purchase_order_id' => $order->getKey(),
                'user_id' => $this->ownerA->getKey(),
                'tenant_id' => $this->a->id,
                'client_request_id' => Str::uuid7()->toString(),
                'items' => [['purchase_order_item_id' => $order->items()->firstOrFail()->getKey(), 'quantity' => 5]],
            ]);

            return app(CreatePurchaseReturn::class)->handle([
                'purchase_order_id' => $order->getKey(),
                'supplier_id' => $supplier->getKey(),
                'user_id' => $this->ownerA->getKey(),
                'tenant_id' => $this->a->id,
                'reason' => 'Barang rusak',
                'client_request_id' => $key,
                'items' => [[
                    'product_id' => $product->getKey(),
                    'qty' => 1,
                    'price_minor' => 3000,
                    'reason' => 'Barang rusak',
                ]],
            ]);
        });

        $this->assertSame($this->a->id, $returnA->tenant_id);

        $returnB = $this->asTenant($this->b, function () use ($key): PurchaseReturn {
            $product = $this->product();
            $supplier = Supplier::factory()->forTenant($this->b)->create();
            $order = $this->purchaseOrder($this->b, $this->ownerB, $supplier, $product);

            app(ReceiveGoods::class)->handle([
                'purchase_order_id' => $order->getKey(),
                'user_id' => $this->ownerB->getKey(),
                'tenant_id' => $this->b->id,
                'client_request_id' => Str::uuid7()->toString(),
                'items' => [['purchase_order_item_id' => $order->items()->firstOrFail()->getKey(), 'quantity' => 5]],
            ]);

            return app(CreatePurchaseReturn::class)->handle([
                'purchase_order_id' => $order->getKey(),
                'supplier_id' => $supplier->getKey(),
                'user_id' => $this->ownerB->getKey(),
                'tenant_id' => $this->b->id,
                'reason' => 'Barang rusak',
                'client_request_id' => $key,
                'items' => [[
                    'product_id' => $product->getKey(),
                    'qty' => 1,
                    'price_minor' => 3000,
                    'reason' => 'Barang rusak',
                ]],
            ]);
        });

        $this->assertSame($this->b->id, $returnB->tenant_id);
        $this->assertNotSame($returnA->getKey(), $returnB->getKey());
    }
}
