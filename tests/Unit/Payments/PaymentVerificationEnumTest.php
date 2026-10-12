<?php

namespace Tests\Unit\Payments;

use App\Enums\PaymentVerification;
use PHPUnit\Framework\TestCase;

/**
 * Kosakata status verifikasi (FR-05) — termasuk status baru yang dibutuhkan
 * Mode B (gateway): `cancelled` dan `partially_refunded`.
 */
class PaymentVerificationEnumTest extends TestCase
{
    public function test_every_case_has_a_label_and_a_colour(): void
    {
        foreach (PaymentVerification::cases() as $case) {
            $this->assertNotSame('', $case->label(), "{$case->value} butuh label");
            $this->assertNotSame('', $case->color(), "{$case->value} butuh warna");
        }
    }

    public function test_only_verified_counts_as_verified(): void
    {
        foreach (PaymentVerification::cases() as $case) {
            $this->assertSame($case === PaymentVerification::Verified, $case->isVerified());
        }
    }

    public function test_the_gateway_vocabulary_is_complete(): void
    {
        $this->assertNotNull(PaymentVerification::tryFrom('cancelled'));
        $this->assertNotNull(PaymentVerification::tryFrom('partially_refunded'));
        $this->assertSame('Dibatalkan', PaymentVerification::Cancelled->label());
        $this->assertSame('Direfund sebagian', PaymentVerification::PartiallyRefunded->label());
    }

    public function test_label_for_falls_back_to_the_raw_value(): void
    {
        $this->assertSame('', PaymentVerification::labelFor(null));
        $this->assertSame('', PaymentVerification::labelFor(''));
        $this->assertSame('Terverifikasi', PaymentVerification::labelFor('verified'));
        $this->assertSame('legacy_value', PaymentVerification::labelFor('legacy_value'));
    }

    public function test_only_final_outcomes_are_settled(): void
    {
        $settled = [PaymentVerification::Verified, PaymentVerification::Refunded, PaymentVerification::PartiallyRefunded];
        $waiting = [PaymentVerification::CashierRecorded, PaymentVerification::AwaitingVerification, PaymentVerification::Failed, PaymentVerification::Cancelled];

        foreach (PaymentVerification::cases() as $case) {
            $this->assertSame(in_array($case, $settled, true), $case->isSettled(), "{$case->value}.isSettled()");
        }

        $this->assertSame($waiting, array_values(array_filter(
            PaymentVerification::cases(),
            fn ($case) => ! $case->isSettled(),
        )));
    }

    public function test_transitions_into_settled_states_are_always_allowed_from_open_states(): void
    {
        foreach ([PaymentVerification::CashierRecorded, PaymentVerification::AwaitingVerification, PaymentVerification::Failed, PaymentVerification::Cancelled] as $from) {
            foreach (PaymentVerification::cases() as $to) {
                $this->assertTrue($from->canTransitionTo($to), "{$from->value} → {$to->value}");
            }
        }
    }

    public function test_a_settled_payment_cannot_be_downgraded_by_a_callback(): void
    {
        // §15: callback yang datang terlambat tidak boleh mencabut verifikasi.
        $this->assertFalse(PaymentVerification::Verified->canTransitionTo(PaymentVerification::AwaitingVerification));
        $this->assertFalse(PaymentVerification::Verified->canTransitionTo(PaymentVerification::Failed));
        $this->assertFalse(PaymentVerification::Verified->canTransitionTo(PaymentVerification::Cancelled));
        $this->assertFalse(PaymentVerification::Verified->canTransitionTo(PaymentVerification::CashierRecorded));

        // Refund adalah penyelesaian yang lebih jauh dan tetap sah.
        $this->assertTrue(PaymentVerification::Verified->canTransitionTo(PaymentVerification::Refunded));
        $this->assertTrue(PaymentVerification::Verified->canTransitionTo(PaymentVerification::PartiallyRefunded));

        // Pembayaran yang sudah direfund tidak dikembalikan ke status lain.
        $this->assertFalse(PaymentVerification::Refunded->canTransitionTo(PaymentVerification::Verified));
        $this->assertFalse(PaymentVerification::PartiallyRefunded->canTransitionTo(PaymentVerification::Refunded));
        $this->assertFalse(PaymentVerification::Refunded->canTransitionTo(PaymentVerification::Failed));

        // Tidak berubah = selalu sah.
        $this->assertTrue(PaymentVerification::Verified->canTransitionTo(PaymentVerification::Verified));
    }
}
