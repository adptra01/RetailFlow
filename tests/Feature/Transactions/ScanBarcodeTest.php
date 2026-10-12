<?php

namespace Tests\Feature\Transactions;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FR-03 "Barcode kamera": hasil scan kamera mengalir ke aksi domain yang sama
 * dengan pencarian manual. Kamera/decode diuji manual di browser (butuh
 * perangkat keras); di sini yang dikunci adalah perilaku aksinya.
 */
class ScanBarcodeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Flux memakai @teleport; dengan APP_DEBUG=true, fragment multi-root
        // memicu MultipleRootElementsDetectedException (pola tes UI yang ada).
        config(['app.debug' => false]);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->user);
    }

    /**
     * Produk aktif dengan barcode tertentu; barcode satuan opsional.
     */
    private function productWithBarcode(string $barcode, ?string $unitBarcode = null): Product
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'barcode' => $barcode,
        ]);

        if ($unitBarcode !== null) {
            $product->units()->first()->update(['barcode' => $unitBarcode]);
        }

        return $product;
    }

    public function test_scanning_a_product_barcode_opens_the_unit_selector(): void
    {
        $product = $this->productWithBarcode('8991002100001');

        Livewire::test('transactions.create')
            ->call('scanBarcode', '8991002100001')
            ->assertSet('productSearch', '8991002100001')
            ->assertSet('selectedProduct.id', $product->id)
            ->assertSet('showUnitModal', true);
    }

    public function test_scanning_trims_whitespace_from_the_decoded_text(): void
    {
        $product = $this->productWithBarcode('8991002100001');

        Livewire::test('transactions.create')
            ->call('scanBarcode', "  8991002100001\n")
            ->assertSet('selectedProduct.id', $product->id);
    }

    public function test_scanning_a_unit_barcode_preselects_that_unit(): void
    {
        $product = $this->productWithBarcode('PRODUCT-BARCODE', 'UNIT-BARCODE-1');

        $unit = $product->units()->where('barcode', 'UNIT-BARCODE-1')->first();

        Livewire::test('transactions.create')
            ->call('scanBarcode', 'UNIT-BARCODE-1')
            ->assertSet('selectedProduct.id', $product->id)
            ->assertSet('selectedUnitId', $unit->id);
    }

    public function test_an_unknown_barcode_keeps_the_search_and_warns(): void
    {
        Livewire::test('transactions.create')
            ->call('scanBarcode', '9999999999999')
            ->assertSet('productSearch', '9999999999999')
            ->assertSet('selectedProduct', null)
            ->assertDispatched('toast-show');
    }

    public function test_an_inactive_product_barcode_does_not_match(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => false,
            'barcode' => 'INACTIVE-BC',
        ]);

        Livewire::test('transactions.create')
            ->call('scanBarcode', 'INACTIVE-BC')
            ->assertSet('selectedProduct', null)
            ->assertDispatched('toast-show');
    }

    public function test_empty_scan_is_ignored(): void
    {
        Livewire::test('transactions.create')
            ->call('scanBarcode', '   ')
            ->assertSet('productSearch', '')
            ->assertNotDispatched('toast-show');
    }
}
