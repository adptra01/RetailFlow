<?php

namespace App\Support\Api;

/**
 * Amplop respons API yang seragam (§18).
 *
 * Klien perangkat membutuhkan konteks yang sama di setiap respons: waktu
 * server (untuk mendeteksi jam perangkat yang melenceng, §11) dan mata uang
 * aktif (agar angka minor unit dapat diterjemahkan tanpa menebak).
 */
final class ApiMeta
{
    /**
     * @return array<string, mixed>
     */
    public static function base(): array
    {
        return [
            'server_time' => now()->toIso8601String(),
            'currency' => [
                'code' => config('pos.currency.code', 'IDR'),
                'minor_exponent' => (int) config('pos.currency.minor_exponent', 0),
            ],
            'version' => [
                'app' => config('pos.version'),
                'schema' => config('pos.schema_version'),
            ],
        ];
    }

    /**
     * Gabungkan konteks dasar dengan tambahan khusus endpoint.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function with(array $extra): array
    {
        return array_merge(self::base(), $extra);
    }
}
