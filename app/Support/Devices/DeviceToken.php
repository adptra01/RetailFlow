<?php

namespace App\Support\Devices;

use Illuminate\Support\Str;

/**
 * Kredensial token perangkat (Sprint 4, NFR-03, §15).
 *
 * Plaintext hanya ditampilkan sekali saat pendaftaran; yang disimpan adalah
 * hash SHA-256 (token berentropi tinggi, jadi SHA-256 cukup — tidak perlu
 * KDF lambat seperti bcrypt yang akan memperlambat setiap request API).
 */
final readonly class DeviceToken
{
    private function __construct(
        public string $plaintext,
        public string $hash,
        public string $prefix,
    ) {}

    public static function generate(): self
    {
        $plaintext = 'rf_'.Str::random(48);

        return new self($plaintext, self::hash($plaintext), substr($plaintext, 0, 12));
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
