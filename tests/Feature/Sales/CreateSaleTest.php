<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CreateSale;
use App\Exceptions\Sales\InsufficientStock;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateSaleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(int $stock = 10, int $price = 15000): Product
    {
        return Product::factory()->create([
            'stock' => $stock,
            'price' => $price,
            'is_unlimited_stock' => false,
        ]);
    }

    /**
     * @param  list<array{product_id: int, quantity: int, unit_price_minor: int}>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $user, array $items, array $overrides = []): array
    {
        $paid = array_sum(array_map(
            fn (array $item) => $item['unit_price_minor'] * $item['quantity'],
            $items,
        ));

        return array_merge([
            'user_id' => $user->id,
            'payment_method' => 'cash',
            'paid_minor' => $paid,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => $items,
        ], $overrides);
    }

    public function test_creates_sale_atomically_with_ledger_and_audit(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create();
        $product = $this->product(stock: 10, price: 15000);

        $sale = app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price_minor' => 15000],
            ]),
        );

        $this->assertTrue(Str::isUuid($sale->uuid));
        $this->assertNotNull($sale->invoice_number);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(30000, $sale->subtotal_minor);
        $this->assertSame(0, $sale->discount_minor);
        $this->assertSame(30000, $sale->total_minor);
        $this->assertSame(30000, $sale->paid_minor);
        $this->assertSame(0, $sale->change_minor);
        $this->assertSame($tenant->id, $sale->tenant_id);

        $this->assertDatabaseHas('transactions', [
            'id' => $sale->id,
            'client_request_id' => $sale->client_request_id,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price_minor' => 15000,
            'subtotal_minor' => 30000,
            'discount_minor' => 0,
        ]);

        $this->assertSame(8, $product->refresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'quantity_change' => -2,
            'before_stock' => 10,
            'after_stock' => 8,
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
        ]);

        // Exactly one ledger entry: the observer must not add a second one.
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sale.created',
            'entity_id' => $sale->uuid,
            'actor_id' => $user->id,
        ]);
    }

    public function test_replaying_the_same_client_request_is_idempotent(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 10);

        $requestId = Str::uuid7()->toString();

        $first = app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000],
            ], ['client_request_id' => $requestId]),
        );

        $second = app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000],
            ], ['client_request_id' => $requestId]),
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(9, $product->refresh()->stock);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_insufficient_stock_rejects_and_rolls_back(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 1);

        try {
            app(CreateSale::class)->handle(
                $this->payload($user, [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_price_minor' => 15000],
                ]),
            );

            $this->fail('InsufficientStock was not thrown.');
        } catch (InsufficientStock $e) {
            $this->assertSame($product->id, $e->productId);
            $this->assertSame(1, $e->available);
            $this->assertSame(2, $e->requested);
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, $product->refresh()->stock);
    }

    public function test_cash_payment_below_total_without_customer_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        try {
            app(CreateSale::class)->handle(
                $this->payload($user, [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000],
                ], ['paid_minor' => 10000]),
            );

            $this->fail('InvalidPaymentAmount was not thrown.');
        } catch (InvalidPaymentAmount) {
            // expected
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(10, $product->refresh()->stock);
    }

    public function test_cash_overpay_produces_change(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 5);

        $sale = app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000],
            ], ['paid_minor' => 20000]),
        );

        $this->assertSame(15000, $sale->total_minor);
        $this->assertSame(20000, $sale->paid_minor);
        $this->assertSame(5000, $sale->change_minor);
        $this->assertSame('paid', $sale->payment_status);
    }

    public function test_non_cash_overpay_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->expectException(InvalidPaymentAmount::class);

        app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000],
            ], ['payment_method' => 'transfer', 'paid_minor' => 20000]),
        );

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_partial_payment_becomes_credit_sale_and_increments_unpaid_balance(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $customer = Customer::create(['name' => 'Budi Santoso']);

        $sale = app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000],
            ], [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'paid_minor' => 10000,
            ]),
        );

        $this->assertSame('credit', $sale->payment_status);
        $this->assertSame(10000, $sale->paid_minor);
        $this->assertSame(0, $sale->change_minor);
        $this->assertSame(5000.0, (float) $customer->refresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'id' => $sale->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Budi Santoso',
            'payment_status' => 'credit',
        ]);
    }

    public function test_full_paid_sale_does_not_touch_customer_balance(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $customer = Customer::create(['name' => 'Budi Santoso']);

        app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000],
            ], [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
            ]),
        );

        $this->assertSame('paid', Transaction::first()->payment_status);
        $this->assertSame(0.0, (float) $customer->refresh()->balance);
    }

    public function test_price_and_discount_are_snapshotted_on_the_line_item(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 3, price: 15000);

        $sale = app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000, 'discount_minor' => 2000],
            ]),
        );

        $product->update(['price' => 25000, 'stock' => $product->stock + 1]);

        $item = $sale->items()->first();

        $this->assertSame(15000, $item->unit_price_minor);
        $this->assertSame(2000, $item->discount_minor);
        $this->assertSame(15000, $item->subtotal_minor);
    }

    public function test_nominal_transaction_discount_reduces_total(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 5);

        $sale = app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price_minor' => 15000],
            ], [
                'discount_minor' => 5000,
                'paid_minor' => 25000,
            ]),
        );

        $this->assertSame(30000, $sale->subtotal_minor);
        $this->assertSame(5000, $sale->discount_minor);
        $this->assertSame(25000, $sale->total_minor);
        $this->assertSame(25000, $sale->paid_minor);
        $this->assertSame(0, $sale->change_minor);
        $this->assertSame('paid', $sale->payment_status);
    }

    public function test_percent_discount_is_rounded_to_minor_units(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 5);

        $sale = app(CreateSale::class)->handle(
            $this->payload($user, [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 10000],
            ], [
                'discount_percent' => 10,
                'paid_minor' => 9000,
            ]),
        );

        $this->assertSame(10000, $sale->subtotal_minor);
        $this->assertSame(1000, $sale->discount_minor);
        $this->assertSame(9000, $sale->total_minor);
    }

    public function test_stock_is_decremented_in_base_units_via_conversion_factor(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 10);

        $sale = app(CreateSale::class)->handle(
            $this->payload($user, [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price_minor' => 15000,
                    'conversion_factor' => 3, // 2 dus x 3 pcs = 6 base units
                ],
            ]),
        );

        $this->assertSame(4, $product->refresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'quantity_change' => -6,
            'before_stock' => 10,
            'after_stock' => 4,
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
        ]);
    }

    public function test_conversion_factor_is_respected_when_checking_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->product(stock: 5);

        try {
            app(CreateSale::class)->handle(
                $this->payload($user, [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                        'unit_price_minor' => 15000,
                        'conversion_factor' => 3, // needs 6, only 5 available
                    ],
                ]),
            );

            $this->fail('InsufficientStock was not thrown.');
        } catch (InsufficientStock $e) {
            $this->assertSame(6, $e->requested);
            $this->assertSame(5, $e->available);
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(5, $product->refresh()->stock);
    }
}
