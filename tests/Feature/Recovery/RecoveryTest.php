<?php

namespace Tests\Feature\Recovery;

use App\Actions\Sales\CreateSale;
use App\Exceptions\Sales\InsufficientStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Uji recovery (Sprint 3 item 9): transaksi yang gagal harus meninggalkan
 * keadaan bersih (atomik, tanpa data parsial) dan operasi boleh diulang.
 */
class RecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $this->user = User::factory()->create();
        $this->product = Product::factory()->create([
            'stock' => 1,
            'price' => 15000,
            'is_unlimited_stock' => false,
        ]);
    }

    public function test_failed_checkout_leaves_no_partial_data(): void
    {
        try {
            app(CreateSale::class)->handle($this->payload(quantity: 5));
            $this->fail('Checkout seharusnya gagal karena stok tidak cukup.');
        } catch (InsufficientStock) {
            // diharapkan
        }

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, TransactionItem::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(1, $this->product->refresh()->stock);
    }

    public function test_retry_after_failed_checkout_succeeds_cleanly(): void
    {
        try {
            app(CreateSale::class)->handle($this->payload(quantity: 5));
        } catch (InsufficientStock) {
            // diharapkan
        }

        $sale = app(CreateSale::class)->handle($this->payload(quantity: 1));

        $this->assertSame(0, $this->product->refresh()->stock);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->count());
    }

    public function test_stock_balance_reconciles_with_ledger(): void
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => 15000,
            'is_unlimited_stock' => false,
        ]);

        app(CreateSale::class)->handle($this->payloadFor($product, 2));
        app(CreateSale::class)->handle($this->payloadFor($product, 3));

        $ledger = (int) StockMovement::query()->where('product_id', $product->id)->sum('quantity_change');

        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(5, 10 + $ledger);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $quantity): array
    {
        return $this->payloadFor($this->product, $quantity);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Product $product, int $quantity): array
    {
        return [
            'user_id' => $this->user->id,
            'payment_method' => 'cash',
            'paid_minor' => 15000 * $quantity,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price_minor' => 15000],
            ],
        ];
    }
}
