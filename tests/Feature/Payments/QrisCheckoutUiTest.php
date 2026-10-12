<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Support\QrisPayloadFactory;
use Tests\TestCase;

/**
 * QRIS statis di titik penjualan (POS) — sisi tampilan & alur simpan (FR-05).
 *
 * Halaman POS adalah Folio+Volt: konten `@volt` diuji lewat komponen Livewire
 * (nama rute `transactions.create`). Dibanding halaman indeks, komponen POS
 * memang mengembalikan fragmennya pada setiap request, jadi `assertSee` setelah
 * `set()` dapat diandalkan di sini.
 */
class QrisCheckoutUiTest extends TestCase
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

        $this->tenant = Tenant::factory()->create([
            'qris_payload' => QrisPayloadFactory::static(merchantName: 'TOKO UJI QRIS'),
            'qris_merchant_name' => 'Toko Uji',
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * @return array{0: Product, 1: ProductUnit}
     */
    private function sellable(int $price = 25000, int $stock = 10): array
    {
        $product = Product::factory()->create([
            'stock' => $stock,
            'price' => $price,
            'is_unlimited_stock' => false,
            'tenant_id' => $this->tenant->id,
        ]);

        $unit = ProductUnit::create([
            'product_id' => $product->id,
            'name' => 'Pcs',
            'abbreviation' => 'pcs',
            'is_base' => true,
            'is_active' => true,
            'conversion_factor' => 1,
        ]);

        return [$product, $unit];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cart(Product $product, ProductUnit $unit, int $quantity = 1, int $price = 25000): array
    {
        return [[
            'cart_key' => $product->id.'-'.$unit->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'unit_name' => $unit->name,
            'unit_abbreviation' => $unit->abbreviation,
            'conversion_factor' => 1,
            'name' => $product->name.' ('.$unit->abbreviation.')',
            'quantity' => $quantity,
            'unit_price' => $price,
            'subtotal' => $price * $quantity,
        ]];
    }

    public function test_the_pos_offers_qris_static_as_a_payment_method(): void
    {
        $this->actingAs($this->user)
            ->get(route('transactions.create'))
            ->assertOk()
            ->assertSee('QRIS (statis)');
    }

    public function test_selecting_qris_static_shows_the_merchant_qr_and_locks_the_amount(): void
    {
        // State `payment_method` diikat ke URL, jadi tampilan QRIS dapat diuji
        // lewat GET biasa (fragmen @volt tidak ikut pada render ulang Livewire).
        $this->actingAs($this->user)
            ->get(route('transactions.create', ['payment_method' => PaymentMethod::QrisStatic->value]))
            ->assertOk()
            ->assertSee('Toko Uji')
            ->assertSee('locked to the total')
            ->assertSee('<svg', false)
            ->assertDontSee('QRIS is not configured for this store');
    }

    public function test_selecting_qris_static_without_a_configured_tenant_warns_instead_of_showing_a_qr(): void
    {
        $tenant = Tenant::factory()->create(['qris_payload' => null]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->get(route('transactions.create', ['payment_method' => PaymentMethod::QrisStatic->value]))
            ->assertOk()
            ->assertSee('QRIS is not configured for this store')
            // Tanpa QRIS, tidak ada QR untuk dipindai dan nominal tidak dikunci.
            ->assertDontSee('Scan this QRIS to pay')
            ->assertDontSee('locked to the total');
    }

    public function test_a_qris_sale_is_saved_with_the_tenant_and_cashier_recorded_status(): void
    {
        [$product, $unit] = $this->sellable();

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit))
            ->set('payment_method', PaymentMethod::QrisStatic->value)
            ->call('confirmSave')
            ->call('save')
            ->assertOk();

        $sale = Transaction::query()->withoutGlobalScopes()->sole();

        $this->assertSame(PaymentMethod::QrisStatic->value, $sale->payment_method);
        $this->assertSame(PaymentVerification::CashierRecorded->value, $sale->payment_verification);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(0, $sale->change_minor);
        $this->assertSame(25000, $sale->paid_minor);
        // POS kini mengatribusikan penjualan ke tenant kasir (§12).
        $this->assertSame($this->tenant->id, $sale->tenant_id);
    }

    public function test_a_qris_sale_is_never_verified_automatically(): void
    {
        [$product, $unit] = $this->sellable();

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit))
            ->set('payment_method', PaymentMethod::QrisStatic->value)
            ->call('confirmSave')
            ->call('save');

        $sale = Transaction::query()->withoutGlobalScopes()->sole();

        $this->assertFalse(PaymentVerification::from($sale->payment_verification)->isVerified());
    }

    public function test_a_qris_sale_is_blocked_when_the_tenant_has_no_qris(): void
    {
        $tenant = Tenant::factory()->create(['qris_payload' => null]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        [$product, $unit] = $this->sellable();

        $this->actingAs($user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit))
            ->set('payment_method', PaymentMethod::QrisStatic->value)
            ->call('confirmSave')
            ->assertSet('showConfirmModal', false);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_cash_sale_still_uses_the_typed_amount(): void
    {
        [$product, $unit] = $this->sellable(price: 25000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit))
            ->set('payment_method', PaymentMethod::Cash->value)
            ->set('paid_amount', 30000)
            ->call('confirmSave')
            ->call('save');

        $sale = Transaction::query()->withoutGlobalScopes()->sole();

        $this->assertSame(PaymentMethod::Cash->value, $sale->payment_method);
        $this->assertSame(30000, $sale->paid_minor);
        $this->assertSame(5000, $sale->change_minor);
    }

    public function test_an_unknown_payment_method_is_rejected_by_validation(): void
    {
        [$product, $unit] = $this->sellable();

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit))
            ->set('payment_method', 'ewallet')
            ->call('confirmSave')
            ->assertHasErrors('payment_method');

        $this->assertDatabaseCount('transactions', 0);
    }
}
