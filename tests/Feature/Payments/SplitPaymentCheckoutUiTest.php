<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Support\QrisPayloadFactory;
use Tests\TestCase;

/**
 * Pembayaran campuran di titik penjualan (POS) — sisi tampilan & alur simpan
 * (FR-05 "Pembayaran campuran").
 *
 * Halaman POS adalah Folio+Volt; kontennya diuji lewat komponen Livewire
 * (`transactions.create`), mengikuti pola QrisCheckoutUiTest.
 */
class SplitPaymentCheckoutUiTest extends TestCase
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

    public function test_the_pos_offers_a_split_payment_switch_but_not_mixed_as_a_method(): void
    {
        $this->actingAs($this->user)
            ->get(route('transactions.create'))
            ->assertOk()
            ->assertSee('Split payment')
            // "mixed" bukan metode yang bisa dipilih kasir, hanya hasil komposisi.
            ->assertDontSee('Campuran');
    }

    public function test_a_split_payment_is_saved_with_each_tender_recorded(): void
    {
        [$product, $unit] = $this->sellable(price: 50000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 50000))
            ->set('split_payment', true)
            ->set('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 30000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount' => 20000],
            ])
            ->call('confirmSave')
            ->assertSet('showConfirmModal', true)
            ->call('save')
            ->assertOk();

        $sale = Transaction::query()->withoutGlobalScopes()->sole();

        $this->assertSame(PaymentMethod::Mixed->value, $sale->payment_method);
        $this->assertSame(50000, $sale->paid_minor);
        $this->assertSame(0, $sale->change_minor);
        $this->assertSame('paid', $sale->payment_status);

        $tenders = TransactionPayment::query()->withoutGlobalScopes()->orderBy('id')->get();

        $this->assertCount(2, $tenders);
        $this->assertSame(PaymentMethod::Cash->value, $tenders[0]->payment_method);
        $this->assertSame(30000, $tenders[0]->amount_minor);
        $this->assertSame(PaymentMethod::DebitCard->value, $tenders[1]->payment_method);
        $this->assertSame(20000, $tenders[1]->amount_minor);
    }

    public function test_cash_overpay_in_a_split_returns_change_and_keeps_the_cart_flow(): void
    {
        [$product, $unit] = $this->sellable(price: 50000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 50000))
            ->set('split_payment', true)
            ->set('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 40000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount' => 20000],
            ])
            ->call('confirmSave')
            ->call('save');

        $sale = Transaction::query()->withoutGlobalScopes()->sole();

        $this->assertSame(60000, $sale->paid_minor);
        $this->assertSame(10000, $sale->change_minor);
    }

    public function test_a_split_with_only_one_positive_tender_is_blocked(): void
    {
        [$product, $unit] = $this->sellable(price: 50000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 50000))
            ->set('split_payment', true)
            ->set('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 50000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount' => 0],
            ])
            ->call('confirmSave')
            ->assertSet('showConfirmModal', false);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_non_cash_tenders_may_not_exceed_the_total(): void
    {
        [$product, $unit] = $this->sellable(price: 50000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 50000))
            ->set('split_payment', true)
            ->set('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 1000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount' => 60000],
            ])
            ->call('confirmSave')
            ->assertSet('showConfirmModal', false);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_qris_tender_without_a_configured_tenant_is_blocked(): void
    {
        [$product, $unit] = $this->sellable(price: 50000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 50000))
            ->set('split_payment', true)
            ->set('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 25000],
                ['payment_method' => PaymentMethod::QrisStatic->value, 'amount' => 25000],
            ])
            ->call('confirmSave')
            ->assertSet('showConfirmModal', false);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_qris_tender_with_a_configured_tenant_is_accepted(): void
    {
        $tenant = Tenant::factory()->create([
            'qris_payload' => QrisPayloadFactory::static(),
            'qris_merchant_name' => 'Toko Uji',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        [$product, $unit] = $this->sellable(price: 50000);

        $this->actingAs($user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 50000))
            ->set('split_payment', true)
            ->set('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 25000],
                ['payment_method' => PaymentMethod::QrisStatic->value, 'amount' => 25000],
            ])
            ->call('confirmSave')
            ->assertSet('showConfirmModal', true)
            ->call('save');

        $sale = Transaction::query()->withoutGlobalScopes()->sole();

        $this->assertSame(PaymentMethod::Mixed->value, $sale->payment_method);
        $this->assertSame($tenant->id, $sale->tenant_id);
    }

    public function test_an_unknown_tender_method_fails_validation(): void
    {
        [$product, $unit] = $this->sellable(price: 50000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 50000))
            ->set('split_payment', true)
            ->set('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 25000],
                ['payment_method' => 'ewallet', 'amount' => 25000],
            ])
            ->call('confirmSave')
            ->assertHasErrors('payments.1.payment_method');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_mixed_is_rejected_as_a_tender_method(): void
    {
        [$product, $unit] = $this->sellable(price: 50000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 50000))
            ->set('split_payment', true)
            ->set('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 25000],
                ['payment_method' => PaymentMethod::Mixed->value, 'amount' => 25000],
            ])
            ->call('confirmSave')
            ->assertHasErrors('payments.1.payment_method');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_add_and_remove_tender_rows_via_volt(): void
    {
        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->assertSet('payments', [
                ['payment_method' => PaymentMethod::Cash->value, 'amount' => 0],
            ])
            ->call('addPayment')
            ->assertCount('payments', 2)
            ->call('removePayment', 0)
            ->assertCount('payments', 1);
    }

    public function test_the_single_method_flow_is_unchanged(): void
    {
        [$product, $unit] = $this->sellable(price: 25000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, price: 25000))
            ->set('split_payment', false)
            ->set('payment_method', PaymentMethod::Cash->value)
            ->set('paid_amount', 30000)
            ->call('confirmSave')
            ->call('save');

        $sale = Transaction::query()->withoutGlobalScopes()->sole();

        $this->assertSame(PaymentMethod::Cash->value, $sale->payment_method);
        $this->assertSame(30000, $sale->paid_minor);
        $this->assertSame(5000, $sale->change_minor);
        $this->assertCount(1, TransactionPayment::query()->withoutGlobalScopes()->get());
    }
}
