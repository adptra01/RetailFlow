<?php

namespace Tests\Feature\Imports;

use App\Actions\Products\ImportProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Imports\ProductCsvParser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportProductsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);
    }

    private function import(): ImportProducts
    {
        return new ImportProducts($this->tenant->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'line' => 2,
            'name' => 'Kopi Arabica 250g',
            'sku' => 'KOPI-AR-250',
            'barcode' => '8991001001001',
            'category' => 'Minuman',
            'price' => '45000',
            'buy_price' => '38000',
            'stock' => '50',
            'min_stock' => '10',
            'is_active' => '1',
        ], $overrides);
    }

    public function test_preview_validates_without_writing_anything(): void
    {
        $result = $this->import()->preview([$this->row()]);

        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->valid);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->failed);
        $this->assertSame('valid', $result->rows[0]['status']);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count());
    }

    public function test_preview_flags_duplicate_sku_against_database_without_writing(): void
    {
        Product::factory()->create(['sku' => 'KOPI-AR-250']);

        $result = $this->import()->preview([$this->row()]);

        $this->assertSame(1, $result->failed);
        $this->assertStringContainsString('sudah digunakan', $result->rows[0]['errors'][0]);
        $this->assertSame(1, Product::count());
    }

    public function test_commit_creates_product_with_all_fields(): void
    {
        $result = $this->import()->commit([$this->row()]);

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->failed);

        $product = Product::first();
        $this->assertSame($result->rows[0]['product_id'], $product->id);
        $this->assertSame('Kopi Arabica 250g', $product->name);
        $this->assertSame('kopi-arabica-250g', $product->slug);
        $this->assertSame('KOPI-AR-250', $product->sku);
        $this->assertSame('8991001001001', $product->barcode);
        $this->assertEquals(45000.0, (float) $product->price);
        $this->assertEquals(38000.0, (float) $product->buy_price);
        $this->assertSame(50, $product->stock);
        $this->assertSame(10, $product->min_stock);
        $this->assertTrue($product->is_active);
        $this->assertFalse($product->is_unlimited_stock);
        $this->assertSame($this->tenant->id, $product->tenant_id);
        $this->assertTrue(Str::isUuid($product->uuid));
        $this->assertSame(1, $product->version);
    }

    public function test_commit_skips_invalid_rows_and_reports_column_errors(): void
    {
        $result = $this->import()->commit([
            $this->row(['line' => 2]),
            $this->row([
                'line' => 3,
                'name' => '',
                'price' => '-5',
                'stock' => '-1',
                'sku' => 'KOPI-B-2',
                'barcode' => '8991001001002',
            ]),
        ]);

        $this->assertSame(2, $result->total);
        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, Product::count());

        $errors = $result->rows[1]['errors'];
        $this->assertStringContainsString('Nama produk wajib diisi', $errors[0]);
        $this->assertStringContainsString('Harga jual harus angka lebih dari 0', $errors[1]);
        $this->assertStringContainsString('Stok harus bilangan bulat', $errors[2]);
        $this->assertSame(3, $result->rows[1]['line']);
    }

    public function test_commit_rejects_duplicate_sku_and_barcode_within_file(): void
    {
        $result = $this->import()->commit([
            $this->row(['line' => 2]),
            $this->row(['line' => 3, 'sku' => 'KOPI-AR-250', 'barcode' => '8991001001002', 'name' => 'Duplikat SKU']),
            $this->row(['line' => 4, 'sku' => 'KOPI-AR-251', 'barcode' => '8991001001001', 'name' => 'Duplikat Barcode']),
        ]);

        $this->assertSame(1, $result->created);
        $this->assertSame(2, $result->failed);
        $this->assertStringContainsString('SKU \'KOPI-AR-250\' duplikat di dalam berkas', $result->rows[1]['errors'][0]);
        $this->assertStringContainsString('Barcode \'8991001001001\' duplikat di dalam berkas', $result->rows[2]['errors'][0]);
        $this->assertSame(1, Product::count());
    }

    public function test_commit_rejects_duplicate_barcode_against_existing_product(): void
    {
        Product::factory()->create(['barcode' => '8991001001001']);

        $result = $this->import()->commit([$this->row()]);

        $this->assertSame(1, $result->failed);
        $this->assertStringContainsString('Barcode \'8991001001001\' sudah digunakan', $result->rows[0]['errors'][0]);
        $this->assertSame(1, Product::count());
    }

    public function test_commit_creates_category_once_and_reuses_it_case_insensitively(): void
    {
        $result = $this->import()->commit([
            $this->row(['line' => 2, 'category' => 'Minuman']),
            $this->row(['line' => 3, 'name' => 'Teh Melati', 'sku' => 'TEH-01', 'barcode' => '8991001001009', 'category' => 'minuman']),
        ]);

        $this->assertSame(2, $result->created);
        $this->assertSame(1, Category::count());

        $category = Category::first();
        $this->assertSame('Minuman', $category->name);
        $this->assertSame('minuman', $category->slug);
        $this->assertSame($this->tenant->id, $category->tenant_id);

        $products = Product::all();
        $this->assertSame($category->id, $products[0]->category_id);
        $this->assertSame($category->id, $products[1]->category_id);
    }

    public function test_commit_applies_defaults_for_optional_fields(): void
    {
        $result = $this->import()->commit([
            ['line' => 2, 'name' => 'Tanpa Data Lengkap', 'sku' => 'TANPA-01', 'price' => '1000', 'is_active' => 'tidak'],
        ]);

        $this->assertSame(1, $result->created);

        $product = Product::first();
        $this->assertSame('TANPA-01', $product->sku);
        $this->assertNull($product->barcode);
        $this->assertNotNull($product->category_id);
        $this->assertEquals(0.0, (float) $product->buy_price);
        $this->assertSame(0, $product->stock);
        $this->assertSame(0, $product->min_stock);
        $this->assertFalse($product->is_active);

        $category = Category::first();
        $this->assertSame('Tanpa Kategori', $category->name);
        $this->assertSame('tanpa-kategori', $category->slug);
    }

    public function test_missing_sku_is_rejected(): void
    {
        $row = $this->row();
        unset($row['sku']);

        $result = $this->import()->preview([$row]);

        $this->assertSame(1, $result->failed);
        $this->assertStringContainsString('SKU wajib diisi', $result->rows[0]['errors'][0]);
        $this->assertSame(0, Product::count());
    }

    public function test_invalid_price_variants_are_rejected(): void
    {
        foreach (['0', '-1', 'abc'] as $price) {
            $result = $this->import()->preview([$this->row(['price' => $price])]);
            $this->assertSame(1, $result->failed, "price '{$price}' harus ditolak.");
            $this->assertStringContainsString('Harga jual', $result->rows[0]['errors'][0]);
        }

        $this->assertSame(0, Product::count());
    }

    public function test_negative_buy_price_is_rejected(): void
    {
        $result = $this->import()->preview([$this->row(['buy_price' => '-1'])]);

        $this->assertSame(1, $result->failed);
        $this->assertStringContainsString('Harga beli', $result->rows[0]['errors'][0]);
    }

    public function test_full_pipeline_parse_then_commit(): void
    {
        $csv = "name,sku,barcode,category,price,buy_price,stock,min_stock,is_active\n"
            ."Kopi Arabica 250g,KOPI-AR-250,8991001001001,Minuman,45000,38000,50,10,1\n";

        $parsed = (new ProductCsvParser)->parse($csv);
        $this->assertSame([], $parsed['errors']);

        $result = $this->import()->commit($parsed['rows']);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, Product::count());
        $this->assertSame('Kopi Arabica 250g', Product::first()->name);
    }
}
