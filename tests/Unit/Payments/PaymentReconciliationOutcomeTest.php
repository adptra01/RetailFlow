<?php

namespace Tests\Unit\Payments;

use App\Enums\PaymentReconciliationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Kosakata hasil rekonsiliasi (plan §14).
 *
 * Nilai-nilai enum ini disimpan di kolom `outcome`
 * `payment_reconciliation_entries` — kontrak yang dikunci lowercase.
 */
class PaymentReconciliationOutcomeTest extends TestCase
{
    public function test_values_are_lowercase_snake_case_contract(): void
    {
        foreach (PaymentReconciliationOutcome::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $case->value, "{$case->value} harus lowercase");
        }
    }

    public function test_every_case_has_a_label(): void
    {
        foreach (PaymentReconciliationOutcome::cases() as $case) {
            $this->assertNotSame('', $case->label(), "{$case->value} butuh label");
        }
    }

    public function test_only_changed_is_effective(): void
    {
        foreach (PaymentReconciliationOutcome::cases() as $case) {
            $this->assertSame($case === PaymentReconciliationOutcome::Changed, $case->isEffective());
        }
    }

    public function test_label_for_falls_back_to_the_raw_value(): void
    {
        $this->assertSame('', PaymentReconciliationOutcome::labelFor(null));
        $this->assertSame('Berubah', PaymentReconciliationOutcome::labelFor('changed'));
        $this->assertSame('legacy_value', PaymentReconciliationOutcome::labelFor('legacy_value'));
    }
}
