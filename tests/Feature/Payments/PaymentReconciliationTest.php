<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\CreatePaymentCharge;
use App\Actions\Payments\ReconcilePaymentCharges;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentReconciliationOutcome;
use App\Enums\PaymentVerification;
use App\Models\PaymentCharge;
use App\Models\PaymentReconciliationEntry;
use App\Models\SyncChange;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Payments\PaymentReconciliationReport;
use App\Payments\ProviderPaymentStatus;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * Mode B — Gateway: "Rekonsiliasi" + "Penanganan timeout" (plan §14).
 *
 * Rekonsiliasi memeriksa ulang charge yang belum final ke penyedia:
 * perubahan sah diterapkan, perubahan yang melanggar aturan domain dilewati
 * dan dilaporkan, dan timeout TIDAK pernah ditafsirkan sebagai keberhasilan
 * maupun kegagalan.
 */
class PaymentReconciliationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->gateway = new FakePaymentGateway('fake');

        $this->app->instance(FakePaymentGateway::class, $this->gateway);

        config([
            'pos.payments.gateways' => array_merge(config('pos.payments.gateways'), [
                'fake' => FakePaymentGateway::class,
            ]),
            'pos.payments.default_gateway' => 'fake',
        ]);
    }

    /**
     * Charge dengan status "pending" persis seperti dibuat POS sungguhan.
     */
    private function charge(): PaymentCharge
    {
        $sale = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'payment_method' => PaymentMethod::QrisStatic->value,
            'payment_verification' => PaymentVerification::CashierRecorded->value,
            'total_minor' => 25000,
            'paid_minor' => 25000,
        ]);

        return app(CreatePaymentCharge::class)->handle(
            $sale,
            new Money(25000),
            Str::uuid7()->toString(),
            PaymentMethod::QrisStatic,
        );
    }

    private function reconcile(array $options = []): PaymentReconciliationReport
    {
        return app(ReconcilePaymentCharges::class)->handle($options);
    }

    public function test_reconciliation_applies_an_unconfirmed_provider_status(): void
    {
        $charge = $this->charge();
        $this->gateway->markAs($charge->provider_reference, ProviderPaymentStatus::Paid);

        $report = $this->reconcile();

        $this->assertSame(1, $report->polled);
        $this->assertSame(1, $report->changed);
        $this->assertSame(0, $report->unchanged);
        $this->assertSame(0, $report->timeout);
        $this->assertSame(0, $report->skipped);

        $this->assertSame(ProviderPaymentStatus::Paid, $charge->refresh()->status);
        $this->assertSame(PaymentVerification::Verified, $charge->refresh()->payment_verification);
        $this->assertSame(
            PaymentVerification::Verified->value,
            $charge->transaction->refresh()->payment_verification,
        );

        $entry = PaymentReconciliationEntry::query()->sole();

        $this->assertSame($report->runId, $entry->run_id);
        $this->assertSame(PaymentReconciliationOutcome::Changed, $entry->outcome);
        $this->assertSame(ProviderPaymentStatus::Pending, $entry->status_before);
        $this->assertSame(ProviderPaymentStatus::Paid, $entry->status_after);
        $this->assertSame(PaymentVerification::AwaitingVerification, $entry->verification_before);
        $this->assertSame(PaymentVerification::Verified, $entry->verification_after);
        $this->assertSame($this->tenant->id, $entry->tenant_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PaymentReconciled->value,
            'tenant_id' => $this->tenant->id,
            'entity_id' => $charge->transaction->uuid,
        ]);

        // Perangkat belajar perubahan lewat jurnal sinkronisasi.
        $this->assertGreaterThanOrEqual(
            1,
            SyncChange::query()->where('entity_id', $charge->transaction->uuid)->count(),
        );
    }

    public function test_reconciliation_counts_an_unchanged_charge_without_auditing(): void
    {
        $charge = $this->charge();
        $syncedAt = $charge->last_synced_at;

        $report = $this->reconcile();

        $this->assertSame(1, $report->unchanged);
        $this->assertSame(0, $report->changed);

        $this->assertSame(ProviderPaymentStatus::Pending, $charge->refresh()->status);
        $this->assertSame(PaymentVerification::AwaitingVerification, $charge->refresh()->payment_verification);
        $this->assertNotNull($charge->refresh()->last_synced_at);
        $this->assertTrue($charge->refresh()->last_synced_at->gte($syncedAt));

        $this->assertSame(PaymentReconciliationOutcome::Unchanged, PaymentReconciliationEntry::query()->sole()->outcome);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::PaymentReconciled->value]);
    }

    public function test_reconciliation_marks_timeout_and_retries_later(): void
    {
        $charge = $this->charge();
        $this->gateway->markUnreachable();

        $report = $this->reconcile();

        $this->assertSame(1, $report->timeout);
        $this->assertSame(ProviderPaymentStatus::Unknown, $charge->refresh()->status);
        // Timeout bukan kegagalan: verifikasi lokal tidak disentuh (§14).
        $this->assertSame(PaymentVerification::AwaitingVerification, $charge->refresh()->payment_verification);
        $this->assertSame(
            PaymentVerification::AwaitingVerification->value,
            $charge->transaction->refresh()->payment_verification,
        );
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::PaymentReconciled->value]);

        // Charge berstatus "unknown" ikut dipoll putaran berikutnya...
        $this->assertSame(1, $this->reconcile()->timeout);

        // ...dan pulih begitu penyedia tersedia serta menyatakan lunas.
        $this->gateway->markReachable();
        $this->gateway->markAs($charge->provider_reference, ProviderPaymentStatus::Paid);

        $report = $this->reconcile();

        $this->assertSame(1, $report->changed);
        $this->assertSame(PaymentVerification::Verified->value, $charge->transaction->refresh()->payment_verification);
    }

    public function test_reconciliation_skips_a_transition_that_would_downgrade_a_settled_sale(): void
    {
        $charge = $this->charge();

        // Keadaan menyimpang (mis. hasil edit manual): charge masih `pending`
        // padahal penjualan sudah terverifikasi.
        $charge->update(['payment_verification' => PaymentVerification::Verified->value]);
        $charge->transaction->update(['payment_verification' => PaymentVerification::Verified->value]);

        $this->gateway->markAs($charge->provider_reference, ProviderPaymentStatus::Failed);

        $report = $this->reconcile();

        $this->assertSame(1, $report->skipped);
        $this->assertCount(1, $report->mismatches);
        $this->assertStringContainsString('tidak boleh berpindah', (string) $report->mismatches[0]['reason']);

        // Tidak diterapkan: penjualan yang sudah final tidak dicabut diam-diam.
        $this->assertSame(ProviderPaymentStatus::Pending, $charge->refresh()->status);
        $this->assertSame(PaymentVerification::Verified, $charge->refresh()->payment_verification);
        $this->assertSame(
            PaymentVerification::Verified->value,
            $charge->transaction->refresh()->payment_verification,
        );

        $this->assertSame(PaymentReconciliationOutcome::Skipped, PaymentReconciliationEntry::query()->sole()->outcome);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::PaymentReconciled->value]);
    }

    public function test_reconciliation_does_not_poll_terminal_charges(): void
    {
        // Charge yang sudah final sejak dibuat tidak dipoll ulang.
        $sale = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'payment_method' => PaymentMethod::QrisStatic->value,
            'payment_verification' => PaymentVerification::CashierRecorded->value,
        ]);

        $settledGateway = new FakePaymentGateway('fake-settled', true, ProviderPaymentStatus::Paid);
        $this->app->instance(FakePaymentGateway::class, $settledGateway);
        config([
            'pos.payments.gateways' => array_merge(config('pos.payments.gateways'), [
                'fake-settled' => FakePaymentGateway::class,
            ]),
        ]);

        app(CreatePaymentCharge::class)->handle(
            $sale,
            new Money(25000),
            Str::uuid7()->toString(),
            PaymentMethod::QrisStatic,
            provider: 'fake-settled',
        );

        $report = $this->reconcile();

        $this->assertSame(0, $report->polled);
        $this->assertSame(0, PaymentReconciliationEntry::query()->count());
    }

    public function test_reconciliation_can_be_scoped_to_one_tenant(): void
    {
        $this->charge();

        $otherTenant = Tenant::factory()->create();
        $chargeB = Transaction::factory()->create([
            'tenant_id' => $otherTenant->id,
            'payment_method' => PaymentMethod::QrisStatic->value,
            'payment_verification' => PaymentVerification::CashierRecorded->value,
        ]);
        app(CreatePaymentCharge::class)->handle(
            $chargeB,
            new Money(1000),
            Str::uuid7()->toString(),
            PaymentMethod::QrisStatic,
        );

        $report = $this->reconcile(['tenantId' => $this->tenant->id]);

        $this->assertSame(1, $report->polled);
        $entries = PaymentReconciliationEntry::query()->get();

        $this->assertCount(1, $entries);
        $this->assertSame($this->tenant->id, $entries[0]->tenant_id);
    }

    public function test_reconciliation_reports_a_provider_that_is_no_longer_registered(): void
    {
        // Charge lama ditulis ketika penyedia masih terdaftar; kini konfigurasi
        // berubah dan penyedia tidak dikenal lagi.
        $charge = PaymentCharge::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'legacy-vendor',
            'provider_reference' => 'legacy-ref-1',
            'client_request_id' => 'legacy-req-1',
            'status' => ProviderPaymentStatus::Pending->value,
            'payment_verification' => PaymentVerification::AwaitingVerification->value,
            'amount_minor' => 25000,
            'currency' => 'IDR',
        ]);

        $report = $this->reconcile();

        $this->assertSame(1, $report->skipped);
        $this->assertStringContainsString('tidak dikenal', (string) $report->mismatches[0]['reason']);

        // Tidak ada yang berubah pada charge maupun dompet penyimpanannya.
        $this->assertSame(ProviderPaymentStatus::Pending, $charge->refresh()->status);
        $this->assertSame(PaymentVerification::AwaitingVerification, $charge->refresh()->payment_verification);
    }
}
