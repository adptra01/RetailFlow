<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CreateSale;
use App\Exceptions\Sales\CreditLimitExceeded;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\StockMovement;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-07: jatuh tempo piutang & batas kredit pelanggan (Increment 50).
 *
 * Jaminan domain: penjualan kredit selalu punya tenggat; batas kredit toko
 * dapat membatasi (menolak) atau hanya memperingatkan, dan pelanggan tanpa
 * limit tidak pernah terpengaruh.
 */
class CreditLimitAndDueDateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function product(int $priceMinor = 50000): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'stock' => 20,
            'price' => $priceMinor,
            'is_unlimited_stock' => false,
        ]);
    }

    private function customer(int $creditLimitMajor = 0): Customer
    {
        return Customer::create([
            'name' => 'Toko Sederhana',
            'credit_limit' => $creditLimitMajor,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function creditSale(Customer $customer, Product $product, array $overrides = []): Transaction
    {
        return app(CreateSale::class)->handle(array_merge([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'payment_method' => 'cash',
            'paid_minor' => 0,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => $product->price],
            ],
        ], $overrides));
    }

    public function test_credit_sale_gets_default_due_date(): void
    {
        config(['pos.receivables.default_due_days' => 30]);

        $sale = $this->creditSale($this->customer(), $this->product());
        $receivable = Receivable::where('transaction_id', $sale->id)->sole();

        $this->assertNotNull($receivable->due_at);
        $this->assertTrue(
            Carbon::now()->addDays(30)->startOfMinute()->equalTo($receivable->due_at->startOfMinute()),
            'Tenggat piutang harus mengikuti `pos.receivables.default_due_days`.',
        );
    }

    public function test_due_days_can_be_overridden_per_sale(): void
    {
        $sale = $this->creditSale($this->customer(), $this->product(), ['due_days' => 7]);
        $receivable = Receivable::where('transaction_id', $sale->id)->sole();

        $this->assertTrue(Carbon::now()->addDays(7)->startOfMinute()->equalTo($receivable->due_at->startOfMinute()));
    }

    public function test_explicit_due_at_wins(): void
    {
        $sale = $this->creditSale($this->customer(), $this->product(), [
            'due_at' => '2026-12-31T10:00:00+07:00',
        ]);
        $receivable = Receivable::where('transaction_id', $sale->id)->sole();

        $this->assertSame('2026-12-31', $receivable->due_at->format('Y-m-d'));
    }

    public function test_due_days_zero_leaves_receivable_without_due_date(): void
    {
        $sale = $this->creditSale($this->customer(), $this->product(), ['due_days' => 0]);
        $receivable = Receivable::where('transaction_id', $sale->id)->sole();

        $this->assertNull($receivable->due_at);
    }

    public function test_full_payment_creates_no_receivable(): void
    {
        $product = $this->product(20000);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'payment_method' => 'cash',
            'paid_minor' => 20000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 20000],
            ],
        ]);

        $this->assertSame(0, Receivable::where('transaction_id', $sale->id)->count());
    }

    public function test_customer_without_credit_limit_is_never_blocked(): void
    {
        config(['pos.receivables.enforce_credit_limit' => true]);

        $sale = $this->creditSale($this->customer(creditLimitMajor: 0), $this->product(100000));

        $this->assertSame('credit', $sale->refresh()->payment_status);
        $this->assertSame(1, Receivable::where('transaction_id', $sale->id)->count());
    }

    public function test_enforced_limit_rejects_sale_and_writes_nothing(): void
    {
        config(['pos.receivables.enforce_credit_limit' => true]);

        $customer = $this->customer(creditLimitMajor: 30000);
        $product = $this->product(50000);

        try {
            $this->creditSale($customer, $product);
            $this->fail('Penjualan kredit di atas limit seharusnya ditolak.');
        } catch (CreditLimitExceeded $e) {
            $this->assertStringContainsString($customer->name, $e->getMessage());
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Receivable::withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, AuditLog::where('action', 'sale.created')->count());
        $this->assertSame(0.0, (float) $customer->refresh()->balance);
        $this->assertSame(20, $product->refresh()->stock);
    }

    public function test_warn_only_policy_records_sale_over_the_limit(): void
    {
        config(['pos.receivables.enforce_credit_limit' => false]);

        $customer = $this->customer(creditLimitMajor: 30000);
        $sale = $this->creditSale($customer, $this->product(50000));

        $this->assertSame('credit', $sale->refresh()->payment_status);
        $this->assertSame(1, Receivable::where('transaction_id', $sale->id)->count());
        $this->assertSame(50000.0, (float) $customer->refresh()->balance);
    }

    public function test_sale_exactly_at_the_limit_is_allowed(): void
    {
        config(['pos.receivables.enforce_credit_limit' => true]);

        $sale = $this->creditSale($this->customer(creditLimitMajor: 50000), $this->product(50000));

        $this->assertSame('credit', $sale->refresh()->payment_status);
        $this->assertSame(1, Receivable::where('transaction_id', $sale->id)->count());
    }

    public function test_existing_outstanding_receivables_reduce_available_credit(): void
    {
        config(['pos.receivables.enforce_credit_limit' => true]);

        $customer = $this->customer(creditLimitMajor: 60000);
        $product = $this->product(40000);

        // Piutang lama Rp40.000, limit Rp60.000 → sisa Rp20.000.
        $this->creditSale($customer, $product);

        $this->expectException(CreditLimitExceeded::class);

        $this->creditSale($customer, $product);
    }

    public function test_device_sale_payload_carries_the_due_date(): void
    {
        $device = Device::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product(15000);

        app(CreateSale::class)->handle([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'device_id' => $device->id,
            'customer_id' => $this->customer()->id,
            'customer_name' => 'Toko Sederhana',
            'payment_method' => 'cash',
            'paid_minor' => 0,
            'due_days' => 14,
            'client_request_id' => 'sale-due-1',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 15000],
            ],
        ]);

        $outbox = SyncOutbox::query()->sole();

        $this->assertNotNull($outbox->payload['due_at']);
        $this->assertSame(
            Carbon::now()->addDays(14)->format('Y-m-d'),
            Carbon::parse($outbox->payload['due_at'])->format('Y-m-d'),
        );
    }
}
