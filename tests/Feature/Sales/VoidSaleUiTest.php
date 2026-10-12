<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CreateSale;
use App\Actions\Sales\VoidSale;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pembatalan terkontrol dari sisi UI (§17.4): tombol hapus permanen diganti
 * aksi "Batalkan" beralasan, hanya untuk pemegang izin `transaction.void`.
 */
class VoidSaleUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->viewer->assignRole('viewer');
    }

    private function sale(): Transaction
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => 15000,
            'is_unlimited_stock' => false,
        ]);

        return app(CreateSale::class)->handle([
            'user_id' => $this->owner->id,
            'payment_method' => 'cash',
            'paid_minor' => 30000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price_minor' => 15000],
            ],
        ]);
    }

    public function test_an_authorised_actor_voids_a_sale_with_a_reason(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->owner);

        Livewire::test('transactions.index')
            ->call('confirmVoid', $sale->id)
            ->set('voidReason', 'Pelanggan membatalkan pesanan')
            ->call('voidTransaction')
            ->assertOk();

        $sale->refresh();
        $this->assertSame('void', $sale->payment_status);
        $this->assertSame('Pelanggan membatalkan pesanan', $sale->void_reason);
        $this->assertSame($this->owner->id, $sale->voided_by);
        $this->assertTrue($sale->stock_restored);
    }

    public function test_a_void_without_a_reason_changes_nothing(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->owner);

        Livewire::test('transactions.index')
            ->call('confirmVoid', $sale->id)
            ->set('voidReason', '   ')
            ->call('voidTransaction')
            ->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNull($sale->voided_at);
    }

    public function test_an_actor_without_the_permission_cannot_void_from_the_list(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->viewer);

        // Aksi tidak ditawarkan kepada aktor tanpa izin…
        $this->get(route('transactions.index'))
            ->assertOk()
            ->assertDontSee('confirmVoid(', false);

        // …dan pemanggilan langsung tetap ditolak gerbang izin domain.
        Livewire::test('transactions.index')
            ->call('confirmVoid', $sale->id)
            ->set('voidReason', 'Coba-coba')
            ->call('voidTransaction')
            ->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNull($sale->voided_at);
    }

    public function test_a_voided_sale_is_marked_in_the_list_and_cannot_be_edited(): void
    {
        $sale = $this->sale();

        app(VoidSale::class)->handle([
            'transaction_id' => $sale->id,
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'Salah input',
        ], $this->owner);

        $this->actingAs($this->owner);

        $this->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('Dibatalkan');

        // Halaman edit menolak menyimpan perubahan pada transaksi batal:
        // dialog konfirmasi tidak pernah terbuka, catatan lama tetap utuh.
        Livewire::test('transactions.[Transaction]', ['transaction' => $sale->refresh()])
            ->call('confirmSave')
            ->assertOk()
            ->assertSet('showConfirmModal', false);

        $this->assertSame('Salah input', $sale->refresh()->void_reason);
        $this->assertSame('void', $sale->payment_status);
    }
}
