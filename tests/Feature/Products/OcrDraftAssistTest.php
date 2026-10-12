<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FR-03 OCR Bantuan Input Produk: hasil OCR wajib diterapkan sebagai DRAFT
 * yang dapat ditinjau pengguna sebelum disimpan (§20 "Hasil OCR wajib
 * ditampilkan sebagai draft untuk diperiksa pengguna"). OCR/decode tidak
 * dapat diotomasi di PHPUnit (butuh browser nyata); yang dikunci di sini
 * adalah perilaku aksi `applyOcrDraft`.
 */
class OcrDraftAssistTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $user = User::factory()->create();
        $this->actingAs($user);

        Category::factory()->create();
    }

    public function test_ocr_draft_fills_empty_name_and_slug(): void
    {
        Livewire::test('products.create')
            ->call('applyOcrDraft', ['name' => 'Indomie Goreng', 'price' => null, 'barcode' => null])
            ->assertSet('name', 'Indomie Goreng')
            ->assertSet('slug', 'indomie-goreng');
    }

    public function test_ocr_draft_does_not_overwrite_existing_name(): void
    {
        Livewire::test('products.create')
            ->set('name', 'Sudah Diisi')
            ->set('slug', 'sudah-diisi')
            ->call('applyOcrDraft', ['name' => 'Indomie Goreng', 'price' => null, 'barcode' => null])
            ->assertSet('name', 'Sudah Diisi')
            ->assertSet('slug', 'sudah-diisi');
    }

    public function test_ocr_draft_fills_price_and_unit_price(): void
    {
        Livewire::test('products.create')
            ->call('applyOcrDraft', ['name' => null, 'price' => 3500, 'barcode' => null])
            ->assertSet('price', 3500.0)
            ->assertSet('units.0.price', 3500.0);
    }

    public function test_ocr_draft_fills_barcode(): void
    {
        Livewire::test('products.create')
            ->call('applyOcrDraft', ['name' => null, 'price' => null, 'barcode' => '8991002100018'])
            ->assertSet('barcode', '8991002100018');
    }

    public function test_ocr_draft_does_not_overwrite_existing_barcode(): void
    {
        Livewire::test('products.create')
            ->set('barcode', 'EXISTING-1')
            ->call('applyOcrDraft', ['name' => null, 'price' => null, 'barcode' => '8991002100018'])
            ->assertSet('barcode', 'EXISTING-1');
    }

    public function test_ocr_draft_notifies_the_user_to_review(): void
    {
        Livewire::test('products.create')
            ->call('applyOcrDraft', ['name' => 'Kopi', 'price' => 5000, 'barcode' => '1234567890'])
            ->assertDispatched('toast-show');
    }
}
