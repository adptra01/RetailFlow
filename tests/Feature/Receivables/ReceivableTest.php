<?php

namespace Tests\Feature\Receivables;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Actions\Sales\CreateSale;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Exceptions\Sales\ReceivableClosed;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceivableTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->user = User::factory()->create();
    }

    /**
     * Creates a credit sale through the domain action.
     *
     * @return array{0: Transaction, 1: Customer, 2: Product}
     */
    private function creditSale(int $totalMinor = 15000, int $paidMinor = 10000): array
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => $totalMinor,
            'is_unlimited_stock' => false,
        ]);

        $customer = Customer::create(['name' => 'Budi Santoso']);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'payment_method' => 'cash',
            'paid_minor' => $paidMinor,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => $totalMinor],
            ],
        ]);

        return [$sale, $customer, $product];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pay(int $receivableId, array $overrides = []): ReceivablePayment
    {
        return app(RecordReceivablePayment::class)->handle(array_merge([
            'receivable_id' => $receivableId,
            'amount_minor' => 1000,
            'user_id' => $this->user->id,
        ], $overrides));
    }

    public function test_credit_sale_creates_receivable_from_unpaid_balance(): void
    {
        [$sale, $customer] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);

        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $this->assertNotNull($receivable);
        $this->assertTrue(Str::isUuid($receivable->uuid));
        $this->assertSame($customer->id, $receivable->customer_id);
        $this->assertSame(5000, $receivable->original_amount_minor);
        $this->assertSame(5000, $receivable->remaining_minor);
        $this->assertSame('open', $receivable->status);
        $this->assertSame(5000.0, (float) $customer->refresh()->balance);
    }

    public function test_paid_in_full_sale_creates_no_receivable(): void
    {
        [$sale] = $this->creditSale(totalMinor: 15000, paidMinor: 15000);

        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(0, Receivable::where('transaction_id', $sale->id)->count());
    }

    public function test_partial_payment_reduces_remaining_and_keeps_receivable_open(): void
    {
        [$sale, $customer] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $payment = $this->pay($receivable->id, ['amount_minor' => 3000]);

        $receivable->refresh();

        $this->assertTrue(Str::isUuid($payment->uuid));
        $this->assertSame(3000, $payment->amount_minor);
        $this->assertSame(2000, $receivable->remaining_minor);
        $this->assertSame('open', $receivable->status);
        $this->assertSame(2000.0, (float) $customer->refresh()->balance);
    }

    public function test_final_payment_closes_receivable(): void
    {
        [$sale, $customer] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $this->pay($receivable->id, ['amount_minor' => 5000]);

        $receivable->refresh();

        $this->assertSame(0, $receivable->remaining_minor);
        $this->assertSame('closed', $receivable->status);
        $this->assertSame(0.0, (float) $customer->refresh()->balance);
        $this->assertSame(1, ReceivablePayment::where('receivable_id', $receivable->id)->count());
    }

    public function test_overpay_is_rejected_and_rolls_back(): void
    {
        [$sale, $customer] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        try {
            $this->pay($receivable->id, ['amount_minor' => 6000]);
            $this->fail('InvalidPaymentAmount was not thrown.');
        } catch (InvalidPaymentAmount) {
            // expected
        }

        $this->assertSame(0, ReceivablePayment::count());
        $this->assertSame(5000, $receivable->refresh()->remaining_minor);
        $this->assertSame('open', $receivable->status);
        $this->assertSame(5000.0, (float) $customer->refresh()->balance);
    }

    public function test_payment_on_closed_receivable_is_rejected(): void
    {
        [$sale] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $this->pay($receivable->id, ['amount_minor' => 5000]);
        $this->assertSame('closed', $receivable->refresh()->status);

        $this->expectException(ReceivableClosed::class);

        $this->pay($receivable->id, ['amount_minor' => 1000]);

        $this->assertSame(1, ReceivablePayment::count());
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        [$sale] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        foreach ([0, -500] as $amount) {
            try {
                $this->pay($receivable->id, ['amount_minor' => $amount]);
                $this->fail('InvalidPaymentAmount was not thrown for '.$amount.'.');
            } catch (InvalidPaymentAmount) {
                // expected
            }
        }

        $this->assertSame(0, ReceivablePayment::count());
        $this->assertSame(5000, $receivable->refresh()->remaining_minor);
    }

    public function test_replaying_the_same_client_request_is_idempotent(): void
    {
        [$sale] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $requestId = Str::uuid7()->toString();

        $first = $this->pay($receivable->id, [
            'amount_minor' => 2000,
            'client_request_id' => $requestId,
        ]);

        $second = $this->pay($receivable->id, [
            'amount_minor' => 2000,
            'client_request_id' => $requestId,
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ReceivablePayment::count());
        $this->assertSame(3000, $receivable->refresh()->remaining_minor);
    }

    public function test_payment_writes_audit_log(): void
    {
        [$sale] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $payment = $this->pay($receivable->id, ['amount_minor' => 5000]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'receivable.payment',
            'entity_type' => 'receivable_payment',
            'entity_id' => $payment->uuid,
            'actor_id' => $this->user->id,
        ]);
    }
}
