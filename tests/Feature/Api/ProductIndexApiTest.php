<?php

namespace Tests\Feature\Api;

use App\Enums\Module;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `GET /api/v1/products` (§18) — Increment 53.
 *
 * Katalog klien kasir: hanya produk tenant pengguna (isolasi tenant §12),
 * harga sebagai integer minor unit (§17.1), paginasi, pencarian, dan
 * penyegaran inkremental lewat `updated_since`.
 */
class ProductIndexApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private string $token;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create(['feature_profile' => 'retail']);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        app(TenantContext::class)->set($this->tenant);
        $this->token = $this->owner->createToken('Kasir 1')->plainTextToken;

        $this->category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function index(array $query = []): TestResponse
    {
        $url = '/api/v1/products'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->getJson($url, ['Authorization' => 'Bearer '.$this->token]);
    }

    private function product(string $name, string $sku, float $price, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => $name,
            'slug' => str()->slug($name),
            'sku' => $sku,
            'price' => $price,
            'stock' => 12,
        ], $attributes));
    }

    public function test_it_lists_only_products_of_the_users_tenant_with_minor_unit_prices(): void
    {
        $mine = $this->product('Indomie Goreng', 'IDM-001', 3500.00);

        // Produk tenant lain TIDAK boleh muncul meski konteks tenant ada.
        $this->product('Produk Tetangga', 'NBR-001', 9900.00, [
            'tenant_id' => Tenant::factory()->create()->id,
        ]);

        $response = $this->index()->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($mine->id, $data[0]['id']);
        $this->assertSame(3500, $data[0]['price_minor']);
        $this->assertSame($mine->uuid, $data[0]['uuid']);
        $this->assertSame($this->category->id, $data[0]['category']['id']);
        $this->assertSame(3500, $data[0]['base_unit']['price_minor']);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertFalse($response->json('meta.has_more'));
        $this->assertArrayNotHasKey('buy_price', $data[0]);
        $this->assertArrayNotHasKey('units', $data[0]);
    }

    public function test_inactive_products_are_hidden_unless_explicitly_requested(): void
    {
        $this->product('Produk Aktif', 'ACT-001', 1000.00);
        $inactive = $this->product('Produk Arsip', 'ARC-001', 2000.00, ['is_active' => false]);

        $ids = array_column($this->index()->assertOk()->json('data'), 'id');
        $this->assertNotContains($inactive->id, $ids);

        $ids = array_column($this->index(['include_inactive' => 1])->assertOk()->json('data'), 'id');
        $this->assertContains($inactive->id, $ids);
    }

    public function test_search_matches_name_sku_and_barcode(): void
    {
        $this->product('Kopi Kapal Api', 'KOPI-001', 2500.00, ['barcode' => '8991002101234']);
        $this->product('Teh Celup', 'TEH-002', 3000.00, ['barcode' => '8991002109999']);

        $this->assertSame(1, $this->index(['search' => 'Kapal'])->json('meta.total'));
        $this->assertSame(1, $this->index(['search' => 'TEH-002'])->json('meta.total'));
        $this->assertSame(1, $this->index(['search' => '8991002101234'])->json('meta.total'));
        $this->assertSame(0, $this->index(['search' => 'tidak-ada'])->json('meta.total'));
    }

    public function test_updated_since_supports_incremental_refresh(): void
    {
        $fresh = $this->product('Baru Diubah', 'NEW-001', 1000.00);
        $stale = $this->product('Lama Tidak Diubah', 'OLD-001', 1000.00);

        DB::table('products')
            ->where('id', $stale->id)
            ->update(['updated_at' => now()->subDays(30)]);

        $ids = array_column(
            $this->index(['updated_since' => now()->subDays(7)->toIso8601String()])->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$fresh->id], $ids);
    }

    public function test_pagination_is_capped_at_two_hundred_per_page(): void
    {
        $this->product('Produk A', 'PAG-A', 1000.00);
        $this->product('Produk B', 'PAG-B', 2000.00);

        $response = $this->index(['per_page' => 1])->assertOk();
        $this->assertSame(1, $response->json('meta.per_page'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertTrue($response->json('meta.has_more'));

        $this->index(['per_page' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_include_units_returns_the_full_unit_list(): void
    {
        $product = $this->product('Produk Satuan', 'UNIT-001', 1000.00);

        ProductUnit::query()->create([
            'product_id' => $product->id,
            'name' => 'Dus',
            'abbreviation' => 'Dus',
            'conversion_factor' => 24,
            'is_base' => false,
            'price' => 22000.00,
            'is_active' => true,
        ]);

        $data = $this->index(['include_units' => 1, 'search' => 'UNIT-001'])->assertOk()->json('data.0');

        $this->assertCount(2, $data['units']);
        $prices = array_column($data['units'], 'price_minor');
        $this->assertContains(1000, $prices);
        $this->assertContains(22000, $prices);
    }

    public function test_a_disabled_products_module_blocks_the_catalog(): void
    {
        $this->tenant->update(['enabled_modules' => [Module::Cashier->value]]);
        $this->product('Produk Tersembunyi', 'HID-001', 1000.00);

        $this->index()
            ->assertStatus(403)
            ->assertJsonPath('message', 'Modul produk tidak aktif untuk toko ini.');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/products')->assertStatus(401);
    }
}
