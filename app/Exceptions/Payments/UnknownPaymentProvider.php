<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Penyedia diminta lewat nama yang tidak terdaftar pada registry.
 *
 * Terjadi pada callback/webhook dengan `{provider}` yang salah ketik atau
 * penyedia yang memang belum didaftarkan pada `config('pos.payments.gateways')`.
 */
final class UnknownPaymentProvider extends RuntimeException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $provider, array $known): self
    {
        $knownList = $known === [] ? '(kosong)' : implode(', ', $known);

        return new self("Payment provider \"{$provider}\" tidak dikenal. Penyedia terdaftar: {$knownList}.");
    }
}
