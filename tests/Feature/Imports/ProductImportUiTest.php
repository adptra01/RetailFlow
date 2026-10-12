<?php

namespace Tests\Feature\Imports;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sprint 3 item 1 — UI Impor CSV produk (FR-02).
 *
 * Alur: unggah → pratinjau (validasi tanpa menyimpan) → komit baris valid.
 */
class ProductImportUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Flux memakai @teleport sehingga komponen yang dirender lewat
        // Livewire::test punya lebih dari satu elemen akar; matikan debug agar
        // deteksi elemen akar Livewire tidak menolak render (lihat CategoriesTest).
        config(['app.debug' => false]);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function csv(string $content = ''): UploadedFile
    {
        if ($content === '') {
            $content = implode("\n", [
                'name,sku,barcode,category,price,buy_price,stock,min_stock,is_active',
                'Kopi Arabica 250g,KOPI-AR-250,8991001001001,Minuman,45000,38000,50,10,1',
                ',DUP-SKU,,Minuman,1000,0,0,0,1',
                'Teh Melati 100g,TEH-ML-100,,Minuman,15000,10000,20,5,1',
            ]);
        }

        return UploadedFile::fake()->createWithContent('produk.csv', $content);
    }

    public function test_guests_are_redirected_from_the_import_page(): void
    {
        $this->get(route('imports.products'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_the_import_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('imports.products'))
            ->assertOk()
            ->assertSee('Impor Produk');
    }

    public function test_template_download_returns_a_csv_attachment(): void
    {
        $response = $this->actingAs($this->user)->get(route('imports.products.template'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('name,sku,barcode', $response->getContent());
    }

    public function test_template_download_requires_authentication(): void
    {
        $this->get(route('imports.products.template'))->assertRedirect(route('login'));
    }

    public function test_preview_validates_without_writing_products(): void
    {
        Livewire::actingAs($this->user)
            ->test('imports.products')
            ->set('file', $this->csv())
            ->call('preview')
            ->assertSet('step', 'preview')
            ->assertSet('report.total', 3)
            ->assertSet('report.valid', 2)
            ->assertSet('report.failed', 1);

        // Pratinjau TIDAK menulis apa pun (FR-02).
        $this->assertSame(0, Product::query()->count());
    }

    public function test_commit_creates_only_valid_rows_and_reports_the_rest(): void
    {
        Livewire::actingAs($this->user)
            ->test('imports.products')
            ->set('file', $this->csv())
            ->call('preview')
            ->call('commit')
            ->assertSet('step', 'done')
            ->assertSet('summary.created', 2)
            ->assertSet('summary.failed', 1);

        $this->assertSame(2, Product::query()->count());
        $this->assertDatabaseHas('products', ['sku' => 'KOPI-AR-250', 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseHas('products', ['sku' => 'TEH-ML-100', 'tenant_id' => $this->tenant->id]);
        // Kategori dibuat otomatis (find-or-create).
        $this->assertDatabaseHas('categories', ['name' => 'Minuman', 'tenant_id' => $this->tenant->id]);
    }

    public function test_commit_does_nothing_before_a_preview(): void
    {
        Livewire::actingAs($this->user)
            ->test('imports.products')
            ->call('commit')
            ->assertSet('step', 'upload');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_file_without_required_columns_is_rejected(): void
    {
        $csv = UploadedFile::fake()->createWithContent('salah.csv', "foo,bar\n1,2\n");

        Livewire::actingAs($this->user)
            ->test('imports.products')
            ->set('file', $csv)
            ->call('preview')
            ->assertSet('step', 'upload')
            ->assertNotSet('report.total', 3)
            ->assertSee('name');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_missing_file_fails_validation(): void
    {
        Livewire::actingAs($this->user)
            ->test('imports.products')
            ->call('preview')
            ->assertHasErrors(['file' => 'required']);
    }

    public function test_reset_returns_to_the_upload_step(): void
    {
        Livewire::actingAs($this->user)
            ->test('imports.products')
            ->set('file', $this->csv())
            ->call('preview')
            ->assertSet('step', 'preview')
            ->call('resetImport')
            ->assertSet('step', 'upload')
            ->assertSet('rows', [])
            ->assertSet('report', null);
    }
}
