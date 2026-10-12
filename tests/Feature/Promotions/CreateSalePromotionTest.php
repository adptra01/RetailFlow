<?php

namespace Tests\Feature\Promotions;

use App\Actions\Sales\CreateSale;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-06: transaksi menyimpan snapshot promosi saat penjualan terjadi; perubahan
 * atau penghapusan konfigurasi promosi tidak pernah mengubah transaksi historis.
 */
class CreateSalePromotionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sale_stores_promotion_snapshot_on_transaction_and_items(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $promo = Promotion::factory()->percent(10)->create(['tenant_id' => $tenant->id]);

        $linePromo = Promotion::factory()->productScope(20)->create(['tenant_id' => $tenant->id]);
        $linePromo->products()->attach($product->id);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'payment_method' => 'cash',
            'paid_minor' => 7000, // subtotal 10.000 - diskon promo 3.000 = 7.000
            'client_request_id' => Str::uuid7()->toString(),
            'discount_minor' => 3000,
            'promotion_id' => $promo->id,
            'promotion_name' => $promo->name,
            'promotion_discount_minor' => 3000,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price_minor' => 10_000,
                'discount_minor' => 2000,
                'promotion_id' => $linePromo->id,
                'promotion_name' => $linePromo->name,
            ]],
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $sale->id,
            'discount_minor' => 3000,
            'total_minor' => 7000,
            'promotion_id' => $promo->id,
            'promotion_name' => $promo->name,
            'promotion_discount_minor' => 3000,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $sale->id,
            'product_id' => $product->id,
            'discount_minor' => 2000,
            'promotion_id' => $linePromo->id,
            'promotion_name' => $linePromo->name,
        ]);
    }

    public function test_sale_without_promotion_keeps_snapshot_columns_null(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'payment_method' => 'cash',
            'paid_minor' => 10_000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price_minor' => 10_000,
            ]],
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $sale->id,
            'promotion_id' => null,
            'promotion_name' => null,
            'promotion_discount_minor' => null,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $sale->id,
            'promotion_id' => null,
            'promotion_name' => null,
        ]);
    }

    public function test_deleting_promotion_never_alters_historical_sale(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $promo = Promotion::factory()->percent(10)->create(['tenant_id' => $tenant->id]);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'payment_method' => 'cash',
            'paid_minor' => 9000,
            'client_request_id' => Str::uuid7()->toString(),
            'discount_minor' => 1000,
            'promotion_id' => $promo->id,
            'promotion_name' => $promo->name,
            'promotion_discount_minor' => 1000,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price_minor' => 10_000,
            ]],
        ]);

        $promoName = $promo->name;
        $promo->delete(); // soft delete: baris promosi tersembunyi, FK masih menunjuk

        // Snapshot nama/total historis tetap utuh; FK menunjuk baris soft-deleted.
        $this->assertSame($promo->id, $sale->fresh()->promotion_id);
        $this->assertSame($promoName, $sale->fresh()->promotion_name);
        $this->assertSame(1000, $sale->fresh()->promotion_discount_minor);
        $this->assertSame(9000, $sale->fresh()->total_minor);
        $this->assertSoftDeleted('promotions', ['id' => $promo->id]);
    }
}
