<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Receivable;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FR-07: peringatan batas kredit di titik penjualan (POS), Increment 50.
 *
 * Kebijakan toko menentukan: hanya memperingatkan (penjualan tetap bisa
 * disimpan) atau membatasi (kasir ditahan sebelum modal konfirmasi).
 *
 * Catatan pengujian: halaman POS adalah Folio+Volt; fragmen `@volt` tidak ikut
 * pada render ulang Livewire (lihat QrisCheckoutUiTest), jadi yang diuji di sini
 * adalah PERILAKU aksi (`confirmSave`/`save`) — banner itu sendiri digambar dari
 * `creditLimitInfo` yang sama.
 */
class CreditLimitPosUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        // Halaman POS tidak memakai izin khusus untuk menyimpan penjualan.
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * @return array{0: Product, 1: ProductUnit}
     */
    private function sellable(int $price = 50000): array
    {
        $product = Product::factory()->create([
            'stock' => 10,
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
    private function cart(Product $product, ProductUnit $unit, int $price): array
    {
        return [[
            'cart_key' => $product->id.'-'.$unit->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'unit_name' => $unit->name,
            'unit_abbreviation' => $unit->abbreviation,
            'conversion_factor' => 1,
            'name' => $product->name.' ('.$unit->abbreviation.')',
            'quantity' => 1,
            'unit_price' => $price,
            'subtotal' => $price,
        ]];
    }

    private function customer(int $creditLimitMajor): Customer
    {
        return Customer::create([
            'name' => 'Toko Sederhana',
            'credit_limit' => $creditLimitMajor,
        ]);
    }

    public function test_warn_only_policy_allows_confirmation(): void
    {
        config(['pos.receivables.enforce_credit_limit' => false]);

        [$product, $unit] = $this->sellable(50000);
        $customer = $this->customer(30000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, 50000))
            ->call('selectCustomer', $customer->id, $customer->name)
            ->set('paid_amount', 0)
            ->call('confirmSave')
            ->assertSet('showConfirmModal', true)
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('receivables', [
            'customer_id' => $customer->id,
            'original_amount_minor' => 50000,
        ]);
    }

    public function test_enforced_policy_stops_the_cashier_before_confirmation(): void
    {
        config(['pos.receivables.enforce_credit_limit' => true]);

        [$product, $unit] = $this->sellable(50000);
        $customer = $this->customer(30000);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, 50000))
            ->call('selectCustomer', $customer->id, $customer->name)
            ->set('paid_amount', 0)
            ->call('confirmSave')
            ->assertSet('showConfirmModal', false);

        $this->assertSame(0, Transaction::query()->withoutGlobalScopes()->count());
    }

    public function test_customer_without_limit_is_allowed(): void
    {
        config(['pos.receivables.enforce_credit_limit' => true]);

        [$product, $unit] = $this->sellable(50000);
        $customer = $this->customer(0);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, 50000))
            ->call('selectCustomer', $customer->id, $customer->name)
            ->set('paid_amount', 0)
            ->call('confirmSave')
            ->assertSet('showConfirmModal', true);
    }

    public function test_pos_credit_sale_persists_a_due_date(): void
    {
        config(['pos.receivables.default_due_days' => 21]);

        [$product, $unit] = $this->sellable(50000);
        $customer = $this->customer(0);

        $this->actingAs($this->user);

        Livewire::test('transactions.create')
            ->set('cart', $this->cart($product, $unit, 50000))
            ->call('selectCustomer', $customer->id, $customer->name)
            ->set('paid_amount', 0)
            ->call('confirmSave')
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('receivables', [
            'customer_id' => $customer->id,
            'original_amount_minor' => 50000,
        ]);

        $dueAt = Receivable::query()->withoutGlobalScopes()->sole()->due_at;

        $this->assertNotNull($dueAt);
        $this->assertSame(now()->addDays(21)->format('Y-m-d'), $dueAt->format('Y-m-d'));
    }
}
