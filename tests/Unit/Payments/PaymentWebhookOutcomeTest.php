<?php

namespace Tests\Unit\Payments;

use App\Enums\PaymentWebhookOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Kosakata hasil pemrosesan callback/webhook (plan §14/§15).
 *
 * Nilai-nilai enum ini adalah kontrak API (kolom `outcome` pada
 * `payment_webhook_events` dan jawaban HTTP ke penyedia), jadi casing-nya
 * dikunci: lowercase, bukan PascalCase.
 */
class PaymentWebhookOutcomeTest extends TestCase
{
    public function test_values_are_lowercase_snake_case_contract(): void
    {
        foreach (PaymentWebhookOutcome::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $case->value, "{$case->value} harus lowercase");
        }
    }

    public function test_every_case_has_a_label(): void
    {
        foreach (PaymentWebhookOutcome::cases() as $case) {
            $this->assertNotSame('', $case->label(), "{$case->value} butuh label");
        }
    }

    public function test_only_applied_is_effective(): void
    {
        foreach (PaymentWebhookOutcome::cases() as $case) {
            $this->assertSame($case === PaymentWebhookOutcome::Applied, $case->isEffective());
        }
    }

    public function test_label_for_falls_back_to_the_raw_value(): void
    {
        $this->assertSame('', PaymentWebhookOutcome::labelFor(null));
        $this->assertSame('Diterapkan', PaymentWebhookOutcome::labelFor('applied'));
        $this->assertSame('legacy_value', PaymentWebhookOutcome::labelFor('legacy_value'));
    }
}
