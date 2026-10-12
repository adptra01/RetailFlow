<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\CreatePaymentCharge;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Enums\PaymentWebhookOutcome;
use App\Models\AuditLog;
use App\Models\PaymentCharge;
use App\Models\PaymentWebhookEvent;
use App\Models\SyncChange;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Payments\ProviderPaymentStatus;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * Mode B — Gateway: callback/webhook handler (plan §14) + pengaman §15
 * (tanda tangan, anti-replay, idempotensi callback).
 *
 * Endpoint publik di luar grup `device`; otentikasinya tanda tangan penyedia.
 * Peristiwa yang tidak sah tidak pernah menyentuh domain; peristiwa sah sekali
 * diterapkan; referensi asing dan status tak dikenal dicatat, tidak dibuang.
 */
class PaymentWebhookTest extends TestCase
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
     * Penjualan yang menunggu verifikasi + transaksi pembayaran penyedia,
     * persis seperti alur Mode B sungguhan (POS → createCharge → webhook).
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

    /**
     * Kirim body webhook mentah (persis seperti yang dikirim penyedia).
     *
     * @param  array<string, string>  $headers
     */
    private function postWebhook(string $provider, string $body, array $headers = []): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $this->call('POST', "/api/v1/payments/webhooks/{$provider}", [], [], [], $server, $body);
    }

    /**
     * @return array{0: string, 1: string} body + tanda tangan yang sah
     */
    private function signed(string $eventId, string $reference, ProviderPaymentStatus $status): array
    {
        $body = $this->gateway->webhookBody($eventId, $reference, $status);

        return [$body, $this->gateway->sign($body)];
    }

    // ------------------------------------------------------- penerapan

    public function test_a_paid_webhook_verifies_the_sale(): void
    {
        $charge = $this->charge();
        [$body, $signature] = $this->signed('evt-paid', $charge->provider_reference, ProviderPaymentStatus::Paid);

        $this->postWebhook('fake', $body, ['X-Fake-Signature' => $signature])
            ->assertOk()
            ->assertJson([
                'outcome' => PaymentWebhookOutcome::Applied->value,
                'provider' => 'fake',
                'provider_reference' => $charge->provider_reference,
                'provider_status' => ProviderPaymentStatus::Paid->value,
                'payment_verification' => PaymentVerification::Verified->value,
            ]);

        // Charge + penjualan terverifikasi.
        $this->assertSame(ProviderPaymentStatus::Paid, $charge->refresh()->status);
        $this->assertSame(PaymentVerification::Verified, $charge->refresh()->payment_verification);
        $this->assertSame(
            PaymentVerification::Verified->value,
            $charge->transaction->refresh()->payment_verification,
        );

        // Peristiwa tercatat dengan keterkaitan penuh + jejak audit.
        $this->assertDatabaseHas('payment_webhook_events', [
            'provider' => 'fake',
            'event_id' => 'evt-paid',
            'outcome' => PaymentWebhookOutcome::Applied->value,
            'tenant_id' => $this->tenant->id,
            'payment_charge_id' => $charge->getKey(),
            'transaction_id' => $charge->transaction_id,
            'payment_verification' => PaymentVerification::Verified->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PaymentWebhookApplied->value,
            'tenant_id' => $this->tenant->id,
            'entity_id' => $charge->transaction->uuid,
        ]);

        // Perangkat lain belajar perubahan ini lewat jurnal sinkronisasi.
        $this->assertGreaterThanOrEqual(
            1,
            SyncChange::query()->where('entity_id', $charge->transaction->uuid)->count(),
        );
    }

    public function test_the_same_event_id_is_applied_only_once(): void
    {
        $charge = $this->charge();
        [$body, $signature] = $this->signed('evt-duplikat', $charge->provider_reference, ProviderPaymentStatus::Paid);

        $this->postWebhook('fake', $body, ['X-Fake-Signature' => $signature])->assertOk();
        $this->postWebhook('fake', $body, ['X-Fake-Signature' => $signature])
            ->assertOk()
            ->assertJson(['outcome' => PaymentWebhookOutcome::Duplicate->value]);

        $this->assertSame(1, PaymentWebhookEvent::query()->count());
        $this->assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::PaymentWebhookApplied->value)->count(),
        );
        $this->assertSame(PaymentVerification::Verified->value, $charge->transaction->refresh()->payment_verification);
    }

    public function test_a_webhook_for_an_unknown_reference_is_recorded_but_never_retried(): void
    {
        $charge = $this->charge();
        [$body, $signature] = $this->signed('evt-asing', 'fake-ref-tidak-ada', ProviderPaymentStatus::Paid);

        $this->postWebhook('fake', $body, ['X-Fake-Signature' => $signature])
            ->assertOk()
            ->assertJson(['outcome' => PaymentWebhookOutcome::Unmatched->value]);

        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => 'evt-asing',
            'outcome' => PaymentWebhookOutcome::Unmatched->value,
            'tenant_id' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PaymentWebhookUnmatched->value,
            'tenant_id' => null,
        ]);

        // Tidak ada yang berubah pada penjualan yang sah.
        $this->assertSame(PaymentVerification::AwaitingVerification, $charge->refresh()->payment_verification);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditAction::PaymentWebhookApplied->value,
        ]);
    }

    // -------------------------------------------------- status tak dikenal

    public function test_an_unknown_provider_status_is_ignored_never_assumed_paid(): void
    {
        $charge = $this->charge();

        $body = json_encode([
            'event_id' => 'evt-aneh',
            'reference' => $charge->provider_reference,
            'status' => 'makna_di_bawah_lampu_jalan',
        ], JSON_THROW_ON_ERROR);

        $this->postWebhook('fake', $body, ['X-Fake-Signature' => $this->gateway->sign($body)])
            ->assertOk()
            ->assertJson(['outcome' => PaymentWebhookOutcome::Ignored->value]);

        $event = PaymentWebhookEvent::query()->where('event_id', 'evt-aneh')->sole();

        $this->assertSame(PaymentWebhookOutcome::Ignored, $event->outcome);
        $this->assertSame(ProviderPaymentStatus::Unknown, $event->provider_status);

        // TIDAK dianggap berhasil: status lokal utuh (menunggu).
        $this->assertSame(PaymentVerification::AwaitingVerification, $charge->refresh()->payment_verification);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditAction::PaymentWebhookApplied->value,
        ]);
    }

    // ----------------------------------------------- urutan peristiwa

    public function test_a_stale_pending_after_paid_is_ignored_antireplay(): void
    {
        $charge = $this->charge();

        [$paidBody, $paidSig] = $this->signed('evt-1', $charge->provider_reference, ProviderPaymentStatus::Paid);
        $this->postWebhook('fake', $paidBody, ['X-Fake-Signature' => $paidSig])->assertOk();

        // Replay/urutan terbalik: "pending" datang setelah "paid".
        [$pendingBody, $pendingSig] = $this->signed('evt-2', $charge->provider_reference, ProviderPaymentStatus::Pending);

        $this->postWebhook('fake', $pendingBody, ['X-Fake-Signature' => $pendingSig])
            ->assertOk()
            ->assertJson(['outcome' => PaymentWebhookOutcome::Ignored->value]);

        // Verifikasi TIDAK dicabut oleh peristiwa lama (§15).
        $this->assertSame(PaymentVerification::Verified->value, $charge->transaction->refresh()->payment_verification);

        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => 'evt-2',
            'outcome' => PaymentWebhookOutcome::Ignored->value,
        ]);
    }

    public function test_a_failed_webhook_can_be_followed_by_a_paid_one(): void
    {
        $charge = $this->charge();

        [$failedBody, $failedSig] = $this->signed('evt-x1', $charge->provider_reference, ProviderPaymentStatus::Failed);
        $this->postWebhook('fake', $failedBody, ['X-Fake-Signature' => $failedSig])
            ->assertOk()
            ->assertJson(['outcome' => PaymentWebhookOutcome::Applied->value]);

        $this->assertSame(PaymentVerification::Failed->value, $charge->transaction->refresh()->payment_verification);

        // Pelanggan mencoba lagi dan berhasil — transisi ini sah.
        [$paidBody, $paidSig] = $this->signed('evt-x2', $charge->provider_reference, ProviderPaymentStatus::Paid);
        $this->postWebhook('fake', $paidBody, ['X-Fake-Signature' => $paidSig])
            ->assertOk()
            ->assertJson(['outcome' => PaymentWebhookOutcome::Applied->value]);

        $this->assertSame(PaymentVerification::Verified->value, $charge->transaction->refresh()->payment_verification);
    }

    // ------------------------------------------------------- keamanan

    public function test_an_invalid_signature_is_rejected_without_touching_the_domain(): void
    {
        $charge = $this->charge();
        $body = $this->gateway->webhookBody('evt-palsu', $charge->provider_reference, ProviderPaymentStatus::Paid);

        $this->postWebhook('fake', $body, ['X-Fake-Signature' => 'B64-TIDAK-SAH'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Tanda tangan webhook dari "fake" tidak sah; peristiwa diabaikan.']);

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
        $this->assertSame(PaymentVerification::AwaitingVerification, $charge->refresh()->payment_verification);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PaymentWebhookRejected->value,
            'tenant_id' => null,
        ]);
    }

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $charge = $this->charge();
        $body = $this->gateway->webhookBody('evt-kosong', $charge->provider_reference, ProviderPaymentStatus::Paid);

        $this->postWebhook('fake', $body)
            ->assertStatus(401)
            ->assertJson([
                'provider' => 'fake',
                'message' => 'Webhook dari "fake" tidak menyertakan header tanda tangan "X-Fake-Signature".',
            ]);
    }

    public function test_an_unknown_provider_returns_404(): void
    {
        $body = $this->gateway->webhookBody('evt-1', 'fake-ref-1', ProviderPaymentStatus::Paid);

        $this->postWebhook('tidak-ada', $body, ['X-Fake-Signature' => $this->gateway->sign($body)])
            ->assertStatus(404)
            ->assertJson(['provider' => 'tidak-ada']);
    }

    public function test_an_unconfigured_gateway_returns_503(): void
    {
        // Penyedia `none` terpasang tetapi TIDAK dikonfigurasi — tidak ada
        // tanda tangan yang dapat diverifikasi (FR-01: aplikasi tetap jalan).
        $body = $this->gateway->webhookBody('evt-1', 'fake-ref-1', ProviderPaymentStatus::Paid);

        $this->postWebhook('none', $body, ['X-Fake-Signature' => $this->gateway->sign($body)])
            ->assertStatus(503)
            ->assertJson(['provider' => 'none']);
    }
}
