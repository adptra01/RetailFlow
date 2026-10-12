<?php

namespace Tests\Unit\Payments;

use App\Enums\PaymentVerification;
use App\Payments\ProviderPaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jembatan status penyedia → status verifikasi domain (FR-05, plan §14).
 *
 * Yang paling penting diuji di sini: status yang tidak dikenali TIDAK PERNAH
 * menjadi `verified`.
 */
class ProviderStatusMappingTest extends TestCase
{
    public function test_paid_maps_to_verified(): void
    {
        $this->assertSame(PaymentVerification::Verified, PaymentVerification::forProviderStatus(ProviderPaymentStatus::Paid));
    }

    public function test_pending_maps_to_awaiting_verification_not_verified(): void
    {
        $mapped = PaymentVerification::forProviderStatus(ProviderPaymentStatus::Pending);

        $this->assertSame(PaymentVerification::AwaitingVerification, $mapped);
        $this->assertFalse($mapped->isVerified());
    }

    public function test_unknown_maps_to_null_so_callers_cannot_infer_success(): void
    {
        $this->assertNull(PaymentVerification::forProviderStatus(ProviderPaymentStatus::Unknown));
    }

    /**
     * @return array<string, array{0: ProviderPaymentStatus, 1: PaymentVerification}>
     */
    public static function mappingProvider(): array
    {
        return [
            'pending' => [ProviderPaymentStatus::Pending, PaymentVerification::AwaitingVerification],
            'paid' => [ProviderPaymentStatus::Paid, PaymentVerification::Verified],
            'failed' => [ProviderPaymentStatus::Failed, PaymentVerification::Failed],
            'cancelled' => [ProviderPaymentStatus::Cancelled, PaymentVerification::Cancelled],
            'refunded' => [ProviderPaymentStatus::Refunded, PaymentVerification::Refunded],
            'partially refunded' => [ProviderPaymentStatus::PartiallyRefunded, PaymentVerification::PartiallyRefunded],
        ];
    }

    #[DataProvider('mappingProvider')]
    public function test_every_known_provider_status_maps_to_an_explicit_verification_status(
        ProviderPaymentStatus $provider,
        PaymentVerification $expected,
    ): void {
        $this->assertSame($expected, PaymentVerification::forProviderStatus($provider));
    }

    public function test_only_paid_is_treated_as_a_successful_provider_status(): void
    {
        foreach (ProviderPaymentStatus::cases() as $status) {
            $mapped = PaymentVerification::forProviderStatus($status);

            if ($status === ProviderPaymentStatus::Paid) {
                $this->assertTrue($mapped?->isVerified());

                continue;
            }

            $this->assertFalse($mapped?->isVerified() ?? false, "{$status->value} tidak boleh menjadi terverifikasi");
        }
    }

    public function test_unknown_is_the_only_non_terminal_status_together_with_pending(): void
    {
        $this->assertFalse(ProviderPaymentStatus::Pending->isTerminal());

        foreach ([ProviderPaymentStatus::Paid, ProviderPaymentStatus::Failed, ProviderPaymentStatus::Cancelled, ProviderPaymentStatus::Refunded, ProviderPaymentStatus::PartiallyRefunded] as $status) {
            $this->assertTrue($status->isTerminal(), "{$status->value} harus final");
        }
    }

    public function test_provider_status_labels_are_defined_and_fall_back_safely(): void
    {
        foreach (ProviderPaymentStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }

        $this->assertSame('', ProviderPaymentStatus::labelFor(null));
        $this->assertSame('', ProviderPaymentStatus::labelFor(''));
        $this->assertSame('sesuatu', ProviderPaymentStatus::labelFor('sesuatu'));
        $this->assertSame('Dibayar', ProviderPaymentStatus::labelFor('paid'));
    }
}
