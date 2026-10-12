<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\CreatePaymentCharge;
use App\Console\Commands\ReconcilePayments;
use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Models\PaymentCharge;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Payments\ProviderPaymentStatus;
use App\Support\Money\Money;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * Perintah `php artisan pos:reconcile` (plan §14 — Rekonsiliasi).
 *
 * Perintah ini adalah pintu operasional: boleh dijadwalkan (cron/scheduler)
 * dan aman dijalankan berulang (idempoten). Outputnya ringkas; penyimpangan
 * dilaporkan eksplisit, tidak ditelan diam-diam.
 */
class ReconcilePaymentsCommandTest extends TestCase
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

    public function test_the_command_exists_and_is_documented(): void
    {
        $commands = app(Kernel::class)->all();

        $this->assertArrayHasKey('pos:reconcile', $commands);

        // Dokumentasi membantu operator: perintah menyebutkan tujuannya.
        $this->assertStringContainsString('Rekonsiliasi', (new ReconcilePayments)->getDescription());
    }

    public function test_a_run_without_charges_is_a_clean_success(): void
    {
        $exitCode = Artisan::call('pos:reconcile');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dipoll: 0', Artisan::output());
    }

    public function test_the_command_reports_changes_and_mismatches(): void
    {
        $charge = $this->charge();
        $this->gateway->markAs($charge->provider_reference, ProviderPaymentStatus::Paid);

        $exitCode = Artisan::call('pos:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Berubah: 1', $output);
        $this->assertStringContainsString('Sama: 0', $output);
        $this->assertStringContainsString('Tidak dijawab: 0', $output);
        $this->assertStringContainsString('Dilewati: 0', $output);

        $this->assertSame(PaymentVerification::Verified->value, $charge->transaction->refresh()->payment_verification);
    }

    public function test_the_command_reports_a_mismatch_for_manual_review(): void
    {
        $charge = $this->charge();

        $charge->update(['payment_verification' => PaymentVerification::Verified->value]);
        $charge->transaction->update(['payment_verification' => PaymentVerification::Verified->value]);

        $this->gateway->markAs($charge->provider_reference, ProviderPaymentStatus::Failed);

        $exitCode = Artisan::call('pos:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Penyimpangan — perlu pemeriksaan manual', $output);
        $this->assertStringContainsString('tidak boleh berpindah', $output);
        $this->assertStringContainsString('Dilewati: 1', $output);
    }

    public function test_the_command_can_be_limited_to_one_provider_or_tenant(): void
    {
        $this->charge();

        Artisan::call('pos:reconcile --provider=fake --tenant='.$this->tenant->id);
        $this->assertStringContainsString('Dipoll: 1', Artisan::output());

        Artisan::call('pos:reconcile --provider=tidak-ada --tenant='.$this->tenant->id);
        $this->assertStringContainsString('Dipoll: 0', Artisan::output());
    }

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
}
