<?php

namespace Tests\Feature\Promotions;

use App\Actions\Promotions\EvaluatePromotions;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mesin evaluasi promosi (FR-06, Increment 41): aturan penggabungan satu
 * promosi terbaik per produk + satu per transaksi, min_purchase, periode,
 * cap, dan clamp non-negatif.
 */
class EvaluatePromotionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{Tenant, list<Product>} */
    private function makeStore(int $productCount = 2): array
    {
        $tenant = Tenant::factory()->create();

        $products = [];
        foreach (range(1, $productCount) as $i) {
            $products[] = Product::factory()->create(['stock' => 100]);
        }

        return [$tenant, $products];
    }

    /** @param  list<array{product_id: int, quantity: int}>  $cart */
    private function evaluate(Tenant $tenant, array $cart, array $overrides = []): array
    {
        $items = array_map(
            fn (array $line) => [
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'unit_price_minor' => 10000,
            ],
            $cart,
        );

        return app(EvaluatePromotions::class)->handle(array_merge([
            'tenant_id' => $tenant->id,
            'items' => $items,
        ], $overrides));
    }

    private function cartOf(array $products, array $quantities = []): array
    {
        $cart = [];
        foreach ($products as $index => $product) {
            $cart[] = ['product_id' => $product->id, 'quantity' => $quantities[$index] ?? 1];
        }

        return $cart;
    }

    public function test_empty_cart_yields_zero(): void
    {
        [$tenant] = $this->makeStore();
        Promotion::factory()->percent(10)->create(['tenant_id' => $tenant->id]);

        $result = $this->evaluate($tenant, []);

        $this->assertSame(0, $result['subtotal_minor']);
        $this->assertSame([], $result['line_discounts']);
        $this->assertNull($result['transaction_discount']);
        $this->assertSame(0, $result['total_discount_minor']);
    }

    public function test_transaction_percent_promo_applies_to_subtotal(): void
    {
        [$tenant, [$a]] = $this->makeStore();
        Promotion::factory()->percent(10)->create(['tenant_id' => $tenant->id]);

        // 2 x 10.000 = 20.000; diskon 10% = 2.000.
        $result = $this->evaluate($tenant, $this->cartOf([$a], [2]));

        $this->assertSame(20_000, $result['subtotal_minor']);
        $this->assertNull($result['line_discounts'][0] ?? null);
        $this->assertNotNull($result['transaction_discount']);
        $this->assertSame(2000, $result['transaction_discount']['discount_minor']);
        $this->assertSame(2000, $result['total_discount_minor']);
    }

    public function test_transaction_nominal_promo_applies(): void
    {
        [$tenant, [$a]] = $this->makeStore();
        Promotion::factory()->nominal(5_000, minPurchaseMinor: 15_000)->create(['tenant_id' => $tenant->id]);

        $result = $this->evaluate($tenant, $this->cartOf([$a], [2]));

        $this->assertSame(5000, $result['transaction_discount']['discount_minor']);
    }

    public function test_min_purchase_gate_blocks_discount(): void
    {
        [$tenant, [$a]] = $this->makeStore();
        Promotion::factory()->nominal(5_000, minPurchaseMinor: 30_000)->create(['tenant_id' => $tenant->id]);

        // Subtotal 20.000 < min 30.000 -> tidak berlaku.
        $result = $this->evaluate($tenant, $this->cartOf([$a], [2]));

        $this->assertNull($result['transaction_discount']);
        $this->assertSame(0, $result['total_discount_minor']);
    }

    public function test_period_gate_blocks_expired_and_future_promos(): void
    {
        [$tenant, [$a]] = $this->makeStore();

        Promotion::factory()->percent(10)->create([
            'tenant_id' => $tenant->id,
            'ends_at' => Carbon::now()->subDay(),
        ]);
        Promotion::factory()->percent(20)->create([
            'tenant_id' => $tenant->id,
            'starts_at' => Carbon::now()->addDay(),
        ]);

        $result = $this->evaluate($tenant, $this->cartOf([$a], [2]));

        $this->assertNull($result['transaction_discount']);
        $this->assertSame(0, $result['total_discount_minor']);
    }

    public function test_max_discount_minor_caps_the_discount(): void
    {
        [$tenant, [$a]] = $this->makeStore();
        Promotion::factory()->percent(50)->create([
            'tenant_id' => $tenant->id,
            'max_discount_minor' => 3_000,
        ]);

        // Subtotal 20.000, 50% = 10.000, di-cap 3.000.
        $result = $this->evaluate($tenant, $this->cartOf([$a], [2]));

        $this->assertSame(3000, $result['transaction_discount']['discount_minor']);
    }

    public function test_nominal_discount_never_exceeds_subtotal(): void
    {
        [$tenant, [$a]] = $this->makeStore();
        Promotion::factory()->nominal(100_000)->create(['tenant_id' => $tenant->id]);

        // Subtotal 10.000 tapi nominal promo 100.000 -> clamp ke 10.000.
        $result = $this->evaluate($tenant, $this->cartOf([$a], [1]));

        $this->assertSame(10_000, $result['transaction_discount']['discount_minor']);
        $this->assertSame(0, $result['total_discount_minor'] - 10_000);
    }

    public function test_product_promo_applies_only_to_listed_products(): void
    {
        [$tenant, $products] = $this->makeStore(2);
        [$a, $b] = $products;

        $promo = Promotion::factory()->productScope(20)->create(['tenant_id' => $tenant->id]);
        $promo->products()->attach($a->id);

        // a (10.000) -> diskon 20% = 2.000; b tidak dapat diskon.
        $result = $this->evaluate($tenant, $this->cartOf([$a, $b]));

        $this->assertCount(1, $result['line_discounts']);
        $this->assertSame($a->id, $result['line_discounts'][0]['product_id']);
        $this->assertSame(2000, $result['line_discounts'][0]['discount_minor']);
        $this->assertSame(2000, $result['total_discount_minor']);
    }

    public function test_product_promo_best_discount_wins(): void
    {
        [$tenant, [$a]] = $this->makeStore();

        $small = Promotion::factory()->productScope(10)->create(['tenant_id' => $tenant->id]);
        $large = Promotion::factory()->productScope(25)->create(['tenant_id' => $tenant->id]);
        $small->products()->attach($a->id);
        $large->products()->attach($a->id);

        // 10.000 -> 25% = 2.500 menang atas 10% = 1.000.
        $result = $this->evaluate($tenant, $this->cartOf([$a]));

        $this->assertCount(1, $result['line_discounts']);
        $this->assertSame($large->id, $result['line_discounts'][0]['promotion_id']);
        $this->assertSame(2500, $result['line_discounts'][0]['discount_minor']);
    }

    public function test_priority_breaks_discount_tie(): void
    {
        [$tenant, [$a]] = $this->makeStore();

        $low = Promotion::factory()->productScope(10)->create(['tenant_id' => $tenant->id, 'priority' => 1]);
        $high = Promotion::factory()->productScope(10)->create(['tenant_id' => $tenant->id, 'priority' => 5]);
        $low->products()->attach($a->id);
        $high->products()->attach($a->id);

        // Diskon sama (1.000) -> prioritas tertinggi menang.
        $result = $this->evaluate($tenant, $this->cartOf([$a]));

        $this->assertSame($high->id, $result['line_discounts'][0]['promotion_id']);
    }

    public function test_line_and_transaction_layers_combine(): void
    {
        [$tenant, [$a]] = $this->makeStore();

        $linePromo = Promotion::factory()->productScope(20)->create(['tenant_id' => $tenant->id]);
        $linePromo->products()->attach($a->id);
        Promotion::factory()->percent(10)->create(['tenant_id' => $tenant->id]);

        // a: baris 20% (2.000) + transaksi 10% (10.000-2.000? Tidak —
        // transaksi dihitung dari SUBTOTAL: 10% x 10.000 = 1.000).
        $result = $this->evaluate($tenant, $this->cartOf([$a], [1]));

        $this->assertCount(1, $result['line_discounts']);
        $this->assertSame(2000, $result['line_discounts'][0]['discount_minor']);
        $this->assertSame(1000, $result['transaction_discount']['discount_minor']);
        $this->assertSame(3000, $result['total_discount_minor']);
    }

    public function test_inactive_promotions_are_ignored(): void
    {
        [$tenant, [$a]] = $this->makeStore();
        Promotion::factory()->percent(10)->create(['tenant_id' => $tenant->id, 'is_active' => false]);

        $result = $this->evaluate($tenant, $this->cartOf([$a], [2]));

        $this->assertNull($result['transaction_discount']);
        $this->assertSame(0, $result['total_discount_minor']);
    }

    public function test_promotions_are_isolated_per_tenant(): void
    {
        [$tenantA, [$a]] = $this->makeStore();
        [$tenantB] = $this->makeStore();

        Promotion::factory()->percent(10)->create(['tenant_id' => $tenantA->id]);

        $result = $this->evaluate($tenantB, $this->cartOf([$a], [2]));

        $this->assertNull($result['transaction_discount']);
        $this->assertSame(0, $result['total_discount_minor']);
    }

    public function test_percent_rounds_to_nearest_minor(): void
    {
        [$tenant, [$a]] = $this->makeStore();
        Promotion::factory()->percent(10)->create(['tenant_id' => $tenant->id]);

        // Subtotal 20.000 -> 10% = 2.000 (bilangan bulat).
        // Pakai subtotal ganjil: 3 x 10.000 = 30.000 -> 3.000.
        $result = $this->evaluate($tenant, $this->cartOf([$a], [3]));

        $this->assertSame(3000, $result['transaction_discount']['discount_minor']);
    }

    public function test_cart_lines_are_grouped_per_product(): void
    {
        [$tenant, [$a]] = $this->makeStore();

        $promo = Promotion::factory()->productScope(10)->create(['tenant_id' => $tenant->id]);
        $promo->products()->attach($a->id);

        // Baris terpisah 2x10.000 + 1x10.000 -> subtotal produk 30.000 -> 10% = 3.000.
        $result = app(EvaluatePromotions::class)->handle([
            'tenant_id' => $tenant->id,
            'items' => [
                ['product_id' => $a->id, 'quantity' => 2, 'unit_price_minor' => 10_000],
                ['product_id' => $a->id, 'quantity' => 1, 'unit_price_minor' => 10_000],
            ],
        ]);

        $this->assertSame(30_000, $result['subtotal_minor']);
        $this->assertCount(1, $result['line_discounts']);
        $this->assertSame(3000, $result['line_discounts'][0]['discount_minor']);
    }
}
