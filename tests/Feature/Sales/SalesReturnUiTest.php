<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CreateSale;
use App\Actions\Sales\VoidSale;
use App\Models\Product;
use App\Models\SalesReturn;
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
 * Retur penjualan dari sisi UI (FR-08, §17.4): retur dibuat lewat aksi domain,
 * jumlah dibatasi sisa nota, dan hanya pemegang izin `transaction.return` yang
 * ditawari aksinya.
 */
class SalesReturnUiTest extends TestCase
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

    private function sale(int $quantity = 2): Transaction
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => 15000,
            'is_unlimited_stock' => false,
        ]);

        return app(CreateSale::class)->handle([
            'user_id' => $this->owner->id,
            'payment_method' => 'cash',
            'paid_minor' => 15000 * $quantity,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price_minor' => 15000],
            ],
        ]);
    }

    public function test_an_authorised_actor_records_a_return_from_the_page(): void
    {
        $sale = $this->sale(quantity: 2);
        $product = $sale->items()->firstOrFail()->product;

        $this->assertSame(8, $product->refresh()->stock);

        $this->actingAs($this->owner);

        $this->get(route('returns.sales.index'))
            ->assertOk()
            ->assertSee('Retur Baru');

        Livewire::test('returns.sales.index')
            ->set('txSearch', $sale->invoice_number)
            ->call('searchTransaction')
            ->assertSet('selectedTransactionId', $sale->id)
            ->set('returnReason', 'Kemasan rusak')
            ->call('updateQty', 0, 2)
            ->assertSet('totalMinor', 30000)
            ->call('saveReturn')
            ->assertOk();

        $return = SalesReturn::firstOrFail();

        $this->assertSame('Kemasan rusak', $return->reason);
        $this->assertSame(30000, $return->total_minor);
        $this->assertSame(30000, $return->refund_minor);
        $this->assertSame($this->owner->id, $return->user_id);
        $this->assertSame($sale->id, $return->transaction_id);

        // Stok dikembalikan lewat domain action, bukan oleh UI.
        $this->assertSame(10, $product->refresh()->stock);
    }

    public function test_the_page_shows_the_remaining_quantity_after_an_earlier_return(): void
    {
        $sale = $this->sale(quantity: 2);

        $this->actingAs($this->owner);

        Livewire::test('returns.sales.index')
            ->set('txSearch', $sale->invoice_number)
            ->call('searchTransaction')
            ->set('returnReason', 'Satu rusak')
            ->call('updateQty', 0, 1)
            ->call('saveReturn')
            ->assertOk();

        // Retur kedua: sisa hanya 1, dan nilai/unit tetap menurut nota.
        Livewire::test('returns.sales.index')
            ->set('txSearch', $sale->invoice_number)
            ->call('searchTransaction')
            ->assertSet('returnItems.0.max_qty', 1)
            ->assertSet('returnItems.0.returned_qty', 1)
            ->assertSet('returnItems.0.unit_value_minor', 15000)
            ->set('returnReason', 'Sisa dikembalikan')
            ->call('updateQty', 0, 5)
            ->assertSet('returnItems.0.qty', 1)
            ->call('saveReturn')
            ->assertOk();

        $this->assertSame(2, SalesReturn::count());
    }

    public function test_a_return_without_a_reason_changes_nothing(): void
    {
        $sale = $this->sale();
        $product = $sale->items()->firstOrFail()->product;

        $this->actingAs($this->owner);

        Livewire::test('returns.sales.index')
            ->set('txSearch', $sale->invoice_number)
            ->call('searchTransaction')
            ->set('returnReason', '   ')
            ->call('updateQty', 0, 1)
            ->call('saveReturn')
            ->assertOk();

        $this->assertSame(0, SalesReturn::count());
        $this->assertSame(8, $product->refresh()->stock);
    }

    public function test_an_actor_without_the_permission_is_not_offered_the_action(): void
    {
        $sale = $this->sale();
        $product = $sale->items()->firstOrFail()->product;

        $this->actingAs($this->viewer);

        $this->get(route('returns.sales.index'))
            ->assertOk()
            ->assertDontSee('Retur Baru');

        // Pemanggilan langsung tetap ditolak gerbang izin domain (FR-12).
        Livewire::test('returns.sales.index')
            ->set('txSearch', $sale->invoice_number)
            ->call('searchTransaction')
            ->set('returnReason', 'Coba-coba')
            ->call('updateQty', 0, 1)
            ->call('saveReturn')
            ->assertOk();

        $this->assertSame(0, SalesReturn::count());
        $this->assertSame(8, $product->refresh()->stock);
    }

    public function test_a_voided_sale_cannot_be_selected_for_return(): void
    {
        $sale = $this->sale();

        app(VoidSale::class)->handle([
            'transaction_id' => $sale->id,
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
            'reason' => 'Salah input',
        ], $this->owner);

        $this->actingAs($this->owner);

        Livewire::test('returns.sales.index')
            ->set('txSearch', $sale->invoice_number)
            ->call('searchTransaction')
            ->assertSet('selectedTransactionId', null);

        $this->assertSame(0, SalesReturn::count());
    }
}
