<?php

namespace Tests\Unit\Payments;

use App\Exceptions\Payments\PaymentGatewayUnavailable;
use App\Payments\NullPaymentGateway;
use App\Payments\PaymentChargeRequest;
use App\Payments\ProviderPaymentStatus;
use App\Support\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Adaptor default tanpa gateway (FR-01: aplikasi harus berjalan tanpa payment
 * gateway). Adaptor ini TIDAK boleh membuat pembayaran tampak berhasil.
 */
class NullPaymentGatewayTest extends TestCase
{
    private NullPaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new NullPaymentGateway;
    }

    public function test_it_is_the_none_provider_and_reports_itself_unconfigured(): void
    {
        $this->assertSame('none', $this->gateway->key());
        $this->assertFalse($this->gateway->isConfigured());
    }

    public function test_creating_a_charge_fails_loudly(): void
    {
        $this->expectException(PaymentGatewayUnavailable::class);
        $this->expectExceptionMessage('Belum ada payment gateway');

        $this->gateway->createCharge(new PaymentChargeRequest(
            clientRequestId: 'req-1',
            amount: new Money(25000),
            currency: 'IDR',
        ));
    }

    public function test_status_lookups_report_unknown_rather_than_paid(): void
    {
        $result = $this->gateway->fetchStatus('ref-1');

        $this->assertSame('ref-1', $result->providerReference);
        $this->assertSame(ProviderPaymentStatus::Unknown, $result->status);
        $this->assertFalse($result->status->isTerminal() && $result->status === ProviderPaymentStatus::Paid);
    }

    public function test_webhooks_cannot_be_verified_without_a_provider(): void
    {
        $this->expectException(PaymentGatewayUnavailable::class);

        $this->gateway->verifyAndParseWebhook('{}', []);
    }

    public function test_refunds_are_not_supported(): void
    {
        $this->assertFalse($this->gateway->supportsRefund());

        $this->expectException(PaymentGatewayUnavailable::class);
        $this->expectExceptionMessage('tidak mendukung refund');

        $this->gateway->refund('ref-1', new Money(1000));
    }
}
