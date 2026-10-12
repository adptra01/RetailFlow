<?php

namespace Tests\Unit\Payments;

use App\Exceptions\Payments\UnknownPaymentProvider;
use App\Payments\GatewayRegistry;
use App\Payments\NullPaymentGateway;
use App\Payments\PaymentGateway;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakePaymentGateway;

/**
 * Registry adaptor payment provider (plan §14 "Payment provider adapter").
 */
class GatewayRegistryTest extends TestCase
{
    /**
     * @param  array<string, class-string<PaymentGateway>>  $providers
     * @param  list<class-string>  $resolved
     */
    private function registry(array $providers, string $defaultKey, array &$resolved = []): GatewayRegistry
    {
        return new GatewayRegistry(
            providers: $providers,
            defaultKey: $defaultKey,
            resolver: function (string $class) use (&$resolved): PaymentGateway {
                $resolved[] = $class;

                return new $class;
            },
        );
    }

    public function test_it_resolves_a_registered_provider(): void
    {
        $registry = $this->registry([
            'none' => NullPaymentGateway::class,
            'fake' => FakePaymentGateway::class,
        ], 'none');

        $this->assertInstanceOf(FakePaymentGateway::class, $registry->for('fake'));
        $this->assertSame('fake', $registry->for('fake')->key());
    }

    public function test_the_default_provider_comes_from_configuration(): void
    {
        $registry = $this->registry(['none' => NullPaymentGateway::class], 'none');

        $this->assertSame('none', $registry->defaultKey());
        $this->assertInstanceOf(NullPaymentGateway::class, $registry->default());
    }

    public function test_an_unknown_provider_is_rejected_with_the_known_list(): void
    {
        $registry = $this->registry([
            'none' => NullPaymentGateway::class,
            'fake' => FakePaymentGateway::class,
        ], 'none');

        try {
            $registry->for('midtrans');

            $this->fail('UnknownPaymentProvider tidak dilempar.');
        } catch (UnknownPaymentProvider $e) {
            $this->assertStringContainsString('midtrans', $e->getMessage());
            $this->assertStringContainsString('none', $e->getMessage());
            $this->assertStringContainsString('fake', $e->getMessage());
        }
    }

    public function test_a_misconfigured_adapter_is_a_programming_error(): void
    {
        $registry = new GatewayRegistry(
            providers: ['broken' => \stdClass::class],
            defaultKey: 'broken',
            // Resolver yang mengembalikan objek bukan-PaymentGateway adalah
            // kesalahan konfigurasi pemrograman, bukan kesalahan pengguna.
            resolver: fn (string $class) => new $class,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(PaymentGateway::class);

        $registry->default();
    }

    public function test_adaptors_are_resolved_lazily(): void
    {
        $resolved = [];
        $registry = $this->registry(['none' => NullPaymentGateway::class], 'none', $resolved);

        $this->assertSame([], $resolved, 'Registry tidak boleh membuat adaptor sebelum diminta.');

        $registry->for('none');

        $this->assertSame([NullPaymentGateway::class], $resolved);
    }

    public function test_it_reports_which_providers_are_available(): void
    {
        $registry = $this->registry([
            'none' => NullPaymentGateway::class,
            'fake' => FakePaymentGateway::class,
        ], 'fake');

        $this->assertTrue($registry->has('fake'));
        $this->assertFalse($registry->has('midtrans'));
        $this->assertSame(['none', 'fake'], $registry->keys());
    }
}
