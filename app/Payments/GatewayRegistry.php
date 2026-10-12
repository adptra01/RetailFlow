<?php

namespace App\Payments;

use App\Exceptions\Payments\UnknownPaymentProvider;
use Closure;

/**
 * Registry adaptor payment provider (plan §14 "Payment provider adapter").
 *
 * Memetakan kunci penyedia (mis. `none`) ke kelas adaptor, tanpa mengikat
 * lapisan domain ke satu vendor. Peta ini berasal dari
 * `config('pos.payments.gateways')`, sehingga penyedia baru dapat didaftarkan
 * tanpa mengubah kode pemanggil.
 *
 * Resolver disuntikkan sebagai closure (bukan container) agar registry dapat
 * diuji tanpa aplikasi Laravel dan agar pemanggil mengendalikan pembuatan
 * adaptor (mis. dengan kredensial dari environment).
 */
final class GatewayRegistry
{
    /**
     * @param  array<string, class-string<PaymentGateway>>  $providers
     * @param  Closure(class-string<PaymentGateway>): PaymentGateway  $resolver
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultKey,
        private readonly Closure $resolver,
    ) {}

    /**
     * Adaptor untuk kunci penyedia tertentu.
     *
     * @throws UnknownPaymentProvider
     */
    public function for(string $provider): PaymentGateway
    {
        if (! $this->has($provider)) {
            throw UnknownPaymentProvider::named($provider, $this->keys());
        }

        return $this->make($provider);
    }

    /**
     * Adaptor default instalasi ini (biasanya `none`).
     *
     * @throws UnknownPaymentProvider bila konfigurasi default salah
     */
    public function default(): PaymentGateway
    {
        return $this->for($this->defaultKey);
    }

    public function defaultKey(): string
    {
        return $this->defaultKey;
    }

    public function has(string $provider): bool
    {
        return array_key_exists($provider, $this->providers);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    private function make(string $provider): PaymentGateway
    {
        $gateway = ($this->resolver)($this->providers[$provider]);

        if (! $gateway instanceof PaymentGateway) {
            // Kesalahan pemrograman/konfigurasi, bukan kesalahan pengguna.
            throw new \LogicException(sprintf(
                'Adaptor payment provider "%s" harus mengimplementasikan %s.',
                $this->providers[$provider],
                PaymentGateway::class,
            ));
        }

        return $gateway;
    }
}
