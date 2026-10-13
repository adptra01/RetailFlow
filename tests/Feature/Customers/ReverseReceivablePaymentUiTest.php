<?php

namespace Tests\Feature\Customers;

use App\Enums\FeatureProfile;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Increment 49 — koreksi/pembatalan pembayaran piutang lewat UI (FR-07).
 *
 * Hanya pemegang izin `receivable.payment.correct` yang melihat tombol koreksi
 * dan boleh menjalankannya; koreksi memerlukan alasan dan mengembalikan saldo.
 */
class ReverseReceivablePaymentUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::CreditSales->value,
        ]);
        app(TenantContext::class)->set($this->tenant);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email_verified_at' => now(),
        ]);
        $this->owner->assignRole('owner');

        $this->viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email_verified_at' => now(),
        ]);
        $this->viewer->assignRole('viewer');

        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function payment(int $remainingMinor = 30000, int $amountMinor = 20000): ReceivablePayment
    {
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-REV-001',
        ]);

        $receivable = Receivable::create([
            'tenant_id' => $this->tenant->id,
            'transaction_id' => $transaction->id,
            'customer_id' => $this->customer->id,
            'original_amount_minor' => 50000,
            'remaining_minor' => $remainingMinor,
            'status' => $remainingMinor > 0 ? 'open' : 'closed',
        ]);

        return ReceivablePayment::create([
            'tenant_id' => $this->tenant->id,
            'receivable_id' => $receivable->id,
            'amount_minor' => $amountMinor,
            'payment_method' => 'cash',
            'recorded_by' => $this->owner->id,
            'paid_at' => now(),
        ]);
    }

    public function test_owner_sees_reversal_action_for_active_payment(): void
    {
        $this->payment();

        $this->actingAs($this->owner);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->assertSee('Koreksi');
    }

    public function test_viewer_does_not_see_reversal_action(): void
    {
        $this->payment();

        $this->actingAs($this->viewer);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->assertDontSee('Koreksi');
    }

    public function test_owner_can_reverse_a_payment_through_the_page(): void
    {
        $payment = $this->payment(remainingMinor: 30000, amountMinor: 20000);

        $this->actingAs($this->owner);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openReversalModal', $payment->id)
            ->assertSet('showReversalModal', true)
            ->assertSet('reversalPaymentId', $payment->id)
            ->set('reversalReason', 'Pembayaran ganda')
            ->call('correctPayment')
            ->assertSet('showReversalModal', false);

        $this->assertNotNull($payment->refresh()->reversed_at);
        $this->assertDatabaseHas('receivables', [
            'id' => $payment->receivable_id,
            'remaining_minor' => 50000,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'receivable.payment.corrected',
            'entity_id' => $payment->uuid,
        ]);
    }

    public function test_reversal_requires_a_reason(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->owner);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openReversalModal', $payment->id)
            ->set('reversalReason', '')
            ->call('correctPayment')
            ->assertHasErrors('reversalReason');

        $this->assertNull($payment->refresh()->reversed_at);
    }

    public function test_viewer_cannot_reverse_even_if_invoking_action(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->viewer);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openReversalModal', $payment->id)
            ->set('reversalReason', 'Coba koreksi')
            ->call('correctPayment')
            ->assertDispatched('toast-show');

        $this->assertNull($payment->refresh()->reversed_at);
    }
}
