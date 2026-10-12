<?php

namespace Tests\Unit\Payments;

use App\Enums\PaymentMethod;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentGatewayUnavailable;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentChargeRequest;
use App\Payments\PaymentGateway;
use App\Payments\ProviderPaymentStatus;
use App\Support\Money\Money;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakePaymentGateway;

/**
 * Kontrak port `PaymentGateway` (plan §14 Mode B).
 *
 * Menguji perilaku penyedia terkendali yang MEMENUHI kontrak, sehingga irisan
 * berikutnya (webhook + rekonsiliasi) punya dasar yang sudah terbukti: kunci
 * idempotensi, verifikasi tanda tangan, dan status yang tidak dikenali.
 */
class PaymentGatewayContractTest extends TestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
    }

    private function request(string $clientRequestId = 'req-1', int $minor = 25000): PaymentChargeRequest
    {
        return new PaymentChargeRequest(
            clientRequestId: $clientRequestId,
            amount: new Money($minor),
            currency: 'IDR',
            method: PaymentMethod::QrisStatic,
            tenantId: 'tenant-1',
            description: 'Penjualan INV-1',
        );
    }

    public function test_an_implementation_satisfies_the_port_and_resolves_through_the_registry(): void
    {
        $registry = new GatewayRegistry(
            providers: ['fake' => FakePaymentGateway::class],
            defaultKey: 'fake',
            resolver: fn (string $class) => new $class,
        );

        $this->assertInstanceOf(PaymentGateway::class, $registry->default());
        $this->assertSame('fake', $registry->default()->key());
    }

    public function test_a_charge_returns_a_provider_reference_and_a_neutral_status(): void
    {
        $result = $this->gateway->createCharge($this->request());

        $this->assertSame('fake-ref-1', $result->providerReference);
        $this->assertSame(ProviderPaymentStatus::Pending, $result->status);
        $this->assertArrayHasKey('fake-ref-1', $this->gateway->charges);
        $this->assertSame(25000, $result->raw['amount_minor']);
    }

    public function test_the_same_client_request_id_never_creates_a_second_charge(): void
    {
        $first = $this->gateway->createCharge($this->request('req-same'));
        $second = $this->gateway->createCharge($this->request('req-same'));

        $this->assertSame($first->providerReference, $second->providerReference);
        $this->assertCount(1, $this->gateway->charges);
        // Percobaan ulang tetap tercatat sebagai percobaan (bukan transaksi baru).
        $this->assertCount(2, $this->gateway->attempts);
    }

    public function test_a_different_client_request_id_creates_a_new_charge(): void
    {
        $first = $this->gateway->createCharge($this->request('req-a'));
        $second = $this->gateway->createCharge($this->request('req-b'));

        $this->assertNotSame($first->providerReference, $second->providerReference);
        $this->assertCount(2, $this->gateway->charges);
    }

    public function test_fetch_status_returns_unknown_for_an_unknown_reference(): void
    {
        $this->assertSame(ProviderPaymentStatus::Unknown, $this->gateway->fetchStatus('tidak-ada')->status);
    }

    public function test_a_signed_webhook_is_verified_and_parsed(): void
    {
        $body = $this->gateway->webhookBody('evt-1', 'fake-ref-1', ProviderPaymentStatus::Paid);

        $event = $this->gateway->verifyAndParseWebhook($body, [
            'x-fake-signature' => $this->gateway->sign($body),
        ]);

        $this->assertSame('evt-1', $event->eventId);
        $this->assertSame('fake-ref-1', $event->providerReference);
        $this->assertSame(ProviderPaymentStatus::Paid, $event->status);
    }

    public function test_a_tampered_webhook_body_is_rejected(): void
    {
        $body = $this->gateway->webhookBody('evt-1', 'fake-ref-1', ProviderPaymentStatus::Paid);
        $signature = $this->gateway->sign($body);
        $tampered = $this->gateway->webhookBody('evt-1', 'fake-ref-1', ProviderPaymentStatus::Failed);

        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyAndParseWebhook($tampered, ['x-fake-signature' => $signature]);
    }

    public function test_a_webhook_without_a_signature_header_is_rejected(): void
    {
        $body = $this->gateway->webhookBody('evt-1', 'fake-ref-1', ProviderPaymentStatus::Paid);

        $this->expectException(InvalidWebhookSignature::class);
        $this->expectExceptionMessage('X-Fake-Signature');

        $this->gateway->verifyAndParseWebhook($body, []);
    }

    public function test_refunds_are_recorded_when_supported(): void
    {
        $result = $this->gateway->refund('fake-ref-1', new Money(5000));

        $this->assertSame(ProviderPaymentStatus::Refunded, $result->status);
        $this->assertSame([['reference' => 'fake-ref-1', 'minor' => 5000]], $this->gateway->refunds);
    }

    public function test_an_unconfigured_provider_refuses_to_charge_or_parse_webhooks(): void
    {
        $gateway = new FakePaymentGateway(configured: false);

        $this->assertFalse($gateway->isConfigured());

        try {
            $gateway->createCharge($this->request());

            $this->fail('PaymentGatewayUnavailable tidak dilempar untuk createCharge.');
        } catch (PaymentGatewayUnavailable) {
            // diharapkan
        }

        $this->expectException(PaymentGatewayUnavailable::class);
        $gateway->verifyAndParseWebhook('{}', []);
    }
}
