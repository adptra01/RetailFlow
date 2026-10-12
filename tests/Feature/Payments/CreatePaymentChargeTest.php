<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\CreatePaymentCharge;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Exceptions\Payments\PaymentGatewayUnavailable;
use App\Exceptions\Payments\UnknownPaymentProvider;
use App\Models\AuditLog;
use App\Models\PaymentCharge;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Payments\NullPaymentGateway;
use App\Payments\ProviderPaymentStatus;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * Mode B — Gateway: pembuatan transaksi pembayaran + penyimpanan reference ID
 * provider (plan §14 "Pembuatan transaksi pembayaran", "Penyimpanan reference
 * ID provider").
 *
 * Yang dijaga: idempotensi (FR-04), kredensial di luar DB (FR-05), pemetaan
 * status penyedia ke verifikasi domain (hanya `paid` → terverifikasi), dan
 * kegagalan yang jelas bila gateway belum terpasang (FR-01).
 */
class CreatePaymentChargeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->gateway = $this->registerGateway();
    }

    /**
     * Daftarkan adaptor palsu sebagai penyedia nyata pada registry aplikasi.
     */
    private function registerGateway(ProviderPaymentStatus $status = ProviderPaymentStatus::Pending): FakePaymentGateway
    {
        $gateway = new FakePaymentGateway('fake', true, $status);

        $this->app->instance(FakePaymentGateway::class, $gateway);

        config([
            'pos.payments.gateways' => array_merge(config('pos.payments.gateways'), [
                'fake' => FakePaymentGateway::class,
            ]),
            'pos.payments.default_gateway' => 'fake',
        ]);

        return $gateway;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sale(array $overrides = []): Transaction
    {
        return Transaction::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'payment_method' => PaymentMethod::QrisStatic->value,
            'payment_verification' => PaymentVerification::CashierRecorded->value,
            'total_minor' => 25000,
            'paid_minor' => 25000,
        ], $overrides));
    }

    private function action(): CreatePaymentCharge
    {
        return app(CreatePaymentCharge::class);
    }

    public function test_a_charge_stores_the_provider_reference_and_awaits_verification(): void
    {
        $sale = $this->sale();

        $charge = $this->action()->handle(
            $sale,
            new Money(25000),
            'req-1',
            PaymentMethod::QrisStatic,
        );

        $this->assertSame('fake', $charge->provider);
        $this->assertSame('fake-ref-1', $charge->provider_reference);
        $this->assertSame(ProviderPaymentStatus::Pending, $charge->status);
        $this->assertSame(PaymentVerification::AwaitingVerification, $charge->payment_verification);
        $this->assertSame($sale->getKey(), $charge->transaction_id);
        $this->assertSame(25000, $charge->amount_minor);
        $this->assertSame($this->tenant->id, $charge->tenant_id);
        $this->assertNotNull($charge->last_synced_at);

        // Penjualan belum terverifikasi: penyedia baru menyatakan "pending".
        $this->assertSame(PaymentVerification::AwaitingVerification->value, $sale->refresh()->payment_verification);
    }

    public function test_a_paid_provider_status_verifies_the_sale(): void
    {
        $this->gateway = $this->registerGateway(ProviderPaymentStatus::Paid);
        $sale = $this->sale();

        $charge = $this->action()->handle($sale, new Money(25000), 'req-paid');

        $this->assertSame(ProviderPaymentStatus::Paid, $charge->status);
        $this->assertSame(PaymentVerification::Verified, $charge->payment_verification);
        $this->assertSame(PaymentVerification::Verified->value, $sale->refresh()->payment_verification);
    }

    public function test_the_same_client_request_id_never_creates_a_second_charge(): void
    {
        $sale = $this->sale();

        $first = $this->action()->handle($sale, new Money(25000), 'req-duplikat');
        $second = $this->action()->handle($sale, new Money(25000), 'req-duplikat');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($first->provider_reference, $second->provider_reference);

        // Penyedia hanya dipanggil sekali (FR-04).
        $this->assertCount(1, $this->gateway->attempts);
        $this->assertSame(1, PaymentCharge::query()->count());
    }

    public function test_the_charge_creation_is_audited_without_credentials(): void
    {
        $sale = $this->sale();

        $this->action()->handle($sale, new Money(25000), 'req-audit', PaymentMethod::QrisStatic);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PaymentChargeCreated->value,
            'entity_type' => 'transaction',
            'entity_id' => $sale->uuid,
            'tenant_id' => $this->tenant->id,
        ]);

        $log = AuditLog::query()
            ->where('action', AuditAction::PaymentChargeCreated->value)
            ->sole();

        $this->assertSame('fake-ref-1', $log->metadata['provider_reference']);
        $this->assertSame(25000, $log->metadata['amount_minor']);

        // §15: jejak audit tidak pernah memuat secret.
        $this->assertStringNotContainsString('secret', json_encode($log->metadata));
    }

    public function test_a_settled_sale_is_not_downgraded_by_a_new_charge(): void
    {
        $sale = $this->sale(['payment_verification' => PaymentVerification::Verified->value]);
        $this->gateway = $this->registerGateway(ProviderPaymentStatus::Pending);

        $this->action()->handle($sale, new Money(25000), 'req-lama');

        $this->assertSame(PaymentVerification::Verified->value, $sale->refresh()->payment_verification);
    }

    public function test_an_unconfigured_gateway_fails_loudly_instead_of_faking_success(): void
    {
        $sale = $this->sale();

        $this->expectException(PaymentGatewayUnavailable::class);

        $this->action()->handle(
            $sale,
            new Money(25000),
            'req-none',
            provider: NullPaymentGateway::KEY,
        );
    }

    public function test_an_unknown_provider_is_rejected(): void
    {
        $sale = $this->sale();

        $this->expectException(UnknownPaymentProvider::class);

        $this->action()->handle($sale, new Money(25000), 'req-hantu', provider: 'tidak-ada');
    }

    public function test_a_failed_gateway_call_leaves_no_charge_behind(): void
    {
        $sale = $this->sale();
        $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway('fake', configured: false));

        try {
            $this->action()->handle($sale, new Money(25000), 'req-gagal');
            $this->fail('Adaptor tanpa kredensial seharusnya melempar.');
        } catch (PaymentGatewayUnavailable) {
            // diharapkan
        }

        $this->assertSame(0, PaymentCharge::query()->count());
        $this->assertSame(PaymentVerification::CashierRecorded->value, $sale->refresh()->payment_verification);
    }
}
