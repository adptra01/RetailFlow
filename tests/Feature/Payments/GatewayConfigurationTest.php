<?php

namespace Tests\Feature\Payments;

use App\Exceptions\Payments\UnknownPaymentProvider;
use App\Payments\GatewayRegistry;
use App\Payments\NullPaymentGateway;
use App\Payments\PaymentGateway;
use Tests\TestCase;

/**
 * Pengikatan gateway pada aplikasi nyata (FR-01, FR-05, plan §14/§15).
 *
 * Instalasi tanpa gateway harus tetap berjalan, dan kredensial TIDAK boleh
 * berasal dari database lokal.
 */
class GatewayConfigurationTest extends TestCase
{
    public function test_the_default_provider_is_the_null_gateway(): void
    {
        $this->assertSame(NullPaymentGateway::KEY, config('pos.payments.default_gateway'));
        $this->assertInstanceOf(NullPaymentGateway::class, app(PaymentGateway::class));
        $this->assertFalse(app(PaymentGateway::class)->isConfigured());
    }

    public function test_the_registry_knows_the_registered_providers(): void
    {
        $registry = app(GatewayRegistry::class);

        $this->assertTrue($registry->has(NullPaymentGateway::KEY));
        $this->assertSame([NullPaymentGateway::KEY], $registry->keys());
        $this->assertSame(NullPaymentGateway::KEY, $registry->defaultKey());
    }

    public function test_an_unknown_provider_is_rejected(): void
    {
        $this->expectException(UnknownPaymentProvider::class);

        app(GatewayRegistry::class)->for('tidak-ada');
    }

    public function test_credentials_come_from_configuration_not_the_database(): void
    {
        // FR-05: rahasia gateway tidak disimpan plaintext di DB lokal. Nilai
        // dibaca dari environment/secret store lewat config.
        $credentials = config('pos.payments.credentials');

        $this->assertIsArray($credentials);
        $this->assertArrayHasKey('secret', $credentials);
        $this->assertArrayHasKey('webhook_secret', $credentials);
        $this->assertNull($credentials['secret']);
    }

    public function test_webhook_and_timeout_guards_are_configured(): void
    {
        $this->assertGreaterThan(0, config('pos.payments.timeout'));
        $this->assertGreaterThan(0, config('pos.payments.webhook_tolerance'));
    }
}
