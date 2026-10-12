<?php

namespace Tests\Feature\Customers;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Enums\FeatureProfile;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Increment 36 — Profil Credit Sales (FR-07): detail piutang per pelanggan
 * dan pencatatan pembayaran piutang lewat UI.
 *
 * Uang dalam minor units; IDR ber eksponen 0 sehingga 1 minor == Rp1
 * (lihat App\Support\Money\Money + config pos.currency.minor_exponent).
 */
class CustomerPaymentUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Flux memakai @teleport; dengan APP_DEBUG=true, fragment multi-root
        // memicu MultipleRootElementsDetectedException (pola tes UI yang ada).
        config(['app.debug' => false]);

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::CreditSales->value,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email_verified_at' => now(),
        ]);
        $this->user->assignRole('pemilik');
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->user);
    }

    private function openReceivable(int $remainingMinor = 50000, string $invoice = 'INV-UI-001'): Receivable
    {
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => $invoice,
        ]);

        return Receivable::create([
            'tenant_id' => $this->tenant->id,
            'transaction_id' => $transaction->id,
            'customer_id' => $this->customer->id,
            'original_amount_minor' => 50000,
            'remaining_minor' => $remainingMinor,
            'status' => 'open',
            'due_at' => now()->addDays(7),
        ]);
    }

    public function test_open_payment_modal_prefills_remaining_balance(): void
    {
        $receivable = $this->openReceivable(30000);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openPaymentModal', $receivable->id)
            ->assertSet('selectedReceivableId', $receivable->id)
            ->assertSet('paymentAmount', 30000)
            ->assertSet('showPaymentModal', true)
            ->assertSet('clientRequestId', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_partial_payment_reduces_remaining_balance(): void
    {
        $receivable = $this->openReceivable(50000);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openPaymentModal', $receivable->id)
            ->set('paymentAmount', 20000)
            ->call('recordPayment')
            ->assertSet('showPaymentModal', false);

        $this->assertDatabaseHas('receivable_payments', [
            'receivable_id' => $receivable->id,
            'amount_minor' => 20000,
            'recorded_by' => $this->user->id,
        ]);

        $this->assertDatabaseHas('receivables', [
            'id' => $receivable->id,
            'remaining_minor' => 30000,
            'status' => 'open',
        ]);
    }

    public function test_full_payment_closes_the_receivable(): void
    {
        $receivable = $this->openReceivable(50000);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openPaymentModal', $receivable->id)
            ->set('paymentAmount', 50000)
            ->call('recordPayment')
            ->assertSet('showPaymentModal', false);

        $this->assertDatabaseHas('receivables', [
            'id' => $receivable->id,
            'remaining_minor' => 0,
            'status' => 'closed',
        ]);
    }

    public function test_overpayment_is_rejected_without_recording(): void
    {
        $receivable = $this->openReceivable(50000);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openPaymentModal', $receivable->id)
            ->set('paymentAmount', 60000)
            ->call('recordPayment')
            ->assertDispatched('toast-show');

        $this->assertDatabaseMissing('receivable_payments', [
            'receivable_id' => $receivable->id,
        ]);

        $this->assertDatabaseHas('receivables', [
            'id' => $receivable->id,
            'remaining_minor' => 50000,
            'status' => 'open',
        ]);
    }

    public function test_payment_is_idempotent_on_retry_with_same_request_id(): void
    {
        $receivable = $this->openReceivable(50000);

        $component = Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openPaymentModal', $receivable->id)
            ->set('paymentAmount', 20000);

        $requestId = $component->get('clientRequestId');

        $component->call('recordPayment');

        // Simulasi pengiriman ulang (double-click / retry): kunci idempotensi
        // yang sama tidak boleh menggandakan pembayaran.
        app(RecordReceivablePayment::class)->handle([
            'receivable_id' => $receivable->id,
            'amount_minor' => 20000,
            'payment_method' => 'cash',
            'user_id' => $this->user->id,
            'client_request_id' => $requestId,
        ]);

        $this->assertEquals(1, ReceivablePayment::where('receivable_id', $receivable->id)->count());
        $this->assertEquals(30000, Receivable::find($receivable->id)->remaining_minor);
    }
}
