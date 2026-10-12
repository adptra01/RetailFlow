<?php

namespace Tests\Feature\Payments;

use App\Actions\Sales\CreateSale;
use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Exceptions\Payments\QrisNotConfigured;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Exceptions\Sales\InvalidPaymentVerification;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\QrisPayloadFactory;
use Tests\TestCase;

/**
 * Pembayaran campuran / split payment (FR-05 "Pembayaran campuran").
 *
 * Aturan yang dijaga `CreateSale`:
 *  - daftar `payments` memecah satu penjualan menjadi beberapa tender, masing
 *    masing satu baris `transaction_payments`;
 *  - jumlah tender = `paid_minor`, dan bila `paid_minor` ikut dikirim ia wajib
 *    cocok (tidak boleh ada dua sumber kebenaran);
 *  - total tender non-tunai tidak boleh melebihi total, sehingga kembalian
 *    selalu berasal dari tunai;
 *  - lebih dari satu tender mencatat `payment_method = mixed`;
 *  - satu tender lewat `payments` tetap metode tunggal (bukan mixed), dan QRIS
 *    tunggal tetap wajib tepat total;
 *  - aturan QRIS statis & "instruksi tidak terverifikasi" tetap berlaku;
 *  - penjualan kredit tetap tercipta dari kekurangan bayar (FR-07).
 */
class SplitPaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);
    }

    private function product(int $stock = 10, int $price = 50000): Product
    {
        return Product::factory()->create([
            'stock' => $stock,
            'price' => $price,
            'is_unlimited_stock' => false,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $user, Product $product, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'customer_name' => 'Umum',
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => $product->price],
            ],
        ], $overrides);
    }

    public function test_two_tenders_are_recorded_as_a_mixed_payment(): void
    {
        $product = $this->product(price: 50000);

        $sale = app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 30000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 20000],
            ],
        ]));

        $this->assertSame(PaymentMethod::Mixed->value, $sale->payment_method);
        $this->assertSame(50000, $sale->paid_minor);
        $this->assertSame(0, $sale->change_minor);
        $this->assertSame('paid', $sale->payment_status);

        $tenders = $sale->payments()->orderBy('id')->get();

        $this->assertCount(2, $tenders);
        $this->assertSame(PaymentMethod::Cash->value, $tenders[0]->payment_method);
        $this->assertSame(30000, $tenders[0]->amount_minor);
        $this->assertSame(PaymentMethod::DebitCard->value, $tenders[1]->payment_method);
        $this->assertSame(20000, $tenders[1]->amount_minor);
        // Tiap tender mengikuti status verifikasi penjualan (dicatat kasir).
        $this->assertSame(PaymentVerification::CashierRecorded->value, $tenders[0]->payment_verification);
        $this->assertTrue(Str::isUuid($tenders[0]->uuid));
        $this->assertSame($this->tenant->id, $tenders[0]->tenant_id);
    }

    public function test_cash_overpay_yields_change_while_non_cash_stays_within_total(): void
    {
        $product = $this->product(price: 50000);

        $sale = app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 40000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 20000],
            ],
        ]));

        $this->assertSame(60000, $sale->paid_minor);
        $this->assertSame(10000, $sale->change_minor);
        $this->assertSame('paid', $sale->payment_status);
    }

    public function test_three_tenders_are_all_recorded(): void
    {
        $product = $this->product(price: 60000);

        $sale = app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 10000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 20000],
                ['payment_method' => PaymentMethod::CreditCard->value, 'amount_minor' => 30000],
            ],
        ]));

        $this->assertSame(PaymentMethod::Mixed->value, $sale->payment_method);
        $this->assertSame(60000, $sale->paid_minor);
        $this->assertCount(3, $sale->payments()->get());
    }

    public function test_single_tender_via_payments_stays_a_single_method(): void
    {
        $product = $this->product(price: 25000);

        $sale = app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 25000],
            ],
        ]));

        $this->assertSame(PaymentMethod::DebitCard->value, $sale->payment_method);
        $this->assertSame(25000, $sale->paid_minor);
        $this->assertCount(1, $sale->payments()->get());
    }

    public function test_non_cash_total_cannot_exceed_the_total(): void
    {
        $product = $this->product(price: 50000);

        $this->expectException(InvalidPaymentAmount::class);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 1000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 60000],
            ],
        ]));
    }

    public function test_declared_paid_minor_must_match_the_tender_sum(): void
    {
        $product = $this->product(price: 50000);

        $this->expectException(InvalidPaymentAmount::class);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'paid_minor' => 40000,
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 30000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 20000],
            ],
        ]));
    }

    public function test_tender_amount_must_be_positive(): void
    {
        $product = $this->product(price: 50000);

        $this->expectException(InvalidPaymentAmount::class);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 50000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 0],
            ],
        ]));
    }

    public function test_transfer_cannot_be_marked_verified_in_a_split(): void
    {
        $product = $this->product(price: 50000);

        $this->expectException(InvalidPaymentVerification::class);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payment_verification' => PaymentVerification::Verified->value,
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 20000],
                ['payment_method' => PaymentMethod::Transfer->value, 'amount_minor' => 30000],
            ],
        ]));
    }

    public function test_qris_tender_requires_a_configured_tenant(): void
    {
        $product = $this->product(price: 50000);

        $this->expectException(QrisNotConfigured::class);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 25000],
                ['payment_method' => PaymentMethod::QrisStatic->value, 'amount_minor' => 25000],
            ],
        ]));
    }

    public function test_qris_tender_is_recorded_when_configured(): void
    {
        $tenant = Tenant::factory()->create([
            'qris_payload' => QrisPayloadFactory::static(),
            'qris_merchant_name' => 'Toko Uji',
        ]);
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => 50000,
            'is_unlimited_stock' => false,
            'tenant_id' => $tenant->id,
        ]);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'customer_name' => 'Umum',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 50000]],
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 25000],
                ['payment_method' => PaymentMethod::QrisStatic->value, 'amount_minor' => 25000],
            ],
        ]);

        $this->assertSame(PaymentMethod::Mixed->value, $sale->payment_method);
        $this->assertSame(50000, $sale->paid_minor);
        $this->assertSame($tenant->id, $sale->tenant_id);
        $this->assertCount(2, $sale->payments()->get());
    }

    public function test_single_qris_tender_via_payments_must_equal_the_total(): void
    {
        $tenant = Tenant::factory()->create([
            'qris_payload' => QrisPayloadFactory::static(),
            'qris_merchant_name' => 'Toko Uji',
        ]);
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => 50000,
            'is_unlimited_stock' => false,
            'tenant_id' => $tenant->id,
        ]);

        $this->expectException(InvalidPaymentAmount::class);

        app(CreateSale::class)->handle([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'customer_name' => 'Umum',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 50000]],
            'payments' => [
                ['payment_method' => PaymentMethod::QrisStatic->value, 'amount_minor' => 40000],
            ],
        ]);
    }

    public function test_partial_split_payment_creates_a_receivable_for_the_unpaid_balance(): void
    {
        $product = $this->product(price: 50000);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $sale = app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'customer_id' => $customer->id,
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 20000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 10000],
            ],
        ]));

        $this->assertSame(PaymentMethod::Mixed->value, $sale->payment_method);
        $this->assertSame('credit', $sale->payment_status);
        $this->assertSame(30000, $sale->paid_minor);

        $this->assertDatabaseHas('receivables', [
            'transaction_id' => $sale->id,
            'original_amount_minor' => 20000,
            'remaining_minor' => 20000,
            'status' => 'open',
        ]);
    }

    public function test_split_credit_sale_requires_a_customer(): void
    {
        $product = $this->product(price: 50000);

        $this->expectException(InvalidPaymentAmount::class);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 20000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 10000],
            ],
        ]));
    }

    public function test_replay_is_idempotent_and_does_not_duplicate_tenders(): void
    {
        $product = $this->product(price: 50000);
        $user = $this->user();
        $requestId = Str::uuid7()->toString();

        $payload = $this->payload($user, $product, [
            'client_request_id' => $requestId,
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 30000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 20000],
            ],
        ]);

        $first = app(CreateSale::class)->handle($payload);
        $second = app(CreateSale::class)->handle($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transaction::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, TransactionPayment::query()->withoutGlobalScopes()->count());
    }

    public function test_a_single_method_sale_still_writes_one_tender_row(): void
    {
        $product = $this->product(price: 30000);

        $sale = app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_minor' => 30000,
        ]));

        $tenders = $sale->payments()->get();

        $this->assertCount(1, $tenders);
        $this->assertSame(PaymentMethod::Cash->value, $tenders[0]->payment_method);
        $this->assertSame(30000, $tenders[0]->amount_minor);
    }

    public function test_device_sale_sync_payload_carries_the_tenders(): void
    {
        $device = Device::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product(price: 50000);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'device_id' => $device->id,
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 30000],
                ['payment_method' => PaymentMethod::DebitCard->value, 'amount_minor' => 20000],
            ],
        ]));

        $payload = SyncOutbox::query()->sole()->payload;

        $this->assertSame(PaymentMethod::Mixed->value, $payload['payment_method']);
        $this->assertSame(50000, $payload['paid_minor']);
        $this->assertCount(2, $payload['payments']);
        $this->assertSame(PaymentMethod::DebitCard->value, $payload['payments'][1]['payment_method']);
        $this->assertSame(20000, $payload['payments'][1]['amount_minor']);
    }

    public function test_single_method_device_sale_sync_payload_omits_the_tender_list(): void
    {
        $device = Device::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product(price: 50000);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'device_id' => $device->id,
            'payment_method' => PaymentMethod::Cash->value,
            'paid_minor' => 50000,
        ]));

        $payload = SyncOutbox::query()->sole()->payload;

        $this->assertSame(PaymentMethod::Cash->value, $payload['payment_method']);
        $this->assertNull($payload['payments']);
    }

    public function test_mixed_cannot_be_passed_as_a_plain_payment_method(): void
    {
        $product = $this->product(price: 50000);

        $this->expectException(\InvalidArgumentException::class);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payment_method' => PaymentMethod::Mixed->value,
            'paid_minor' => 50000,
        ]));
    }

    public function test_an_unknown_tender_method_is_rejected(): void
    {
        $product = $this->product(price: 50000);

        $this->expectException(\InvalidArgumentException::class);

        app(CreateSale::class)->handle($this->payload($this->user(), $product, [
            'payments' => [
                ['payment_method' => PaymentMethod::Cash->value, 'amount_minor' => 20000],
                ['payment_method' => 'ewallet', 'amount_minor' => 30000],
            ],
        ]));
    }
}
