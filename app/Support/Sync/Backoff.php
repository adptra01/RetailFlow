<?php

namespace App\Support\Sync;

/**
 * Jadwal backoff pengiriman ulang outbox (§11.3, §11.5).
 *
 * `retry_count` adalah jumlah kegagalan sementara yang sudah terjadi.
 * Sebelum percobaan pertama (`retry_count = 0`) tidak ada jeda; sesudahnya
 * jeda mengikuti `config('pos.sync.retry_backoff')`, dan nilai terakhir
 * dipakai sebagai batas atas.
 */
final class Backoff
{
    /**
     * @return list<int> jeda dalam detik
     */
    public static function schedule(): array
    {
        return array_values(array_map('intval', (array) config('pos.sync.retry_backoff', [1, 5, 10])));
    }

    /**
     * Jeda (detik) sebelum percobaan berikutnya untuk `retry_count` tertentu.
     */
    public static function delayFor(int $retryCount): int
    {
        if ($retryCount <= 0) {
            return 0;
        }

        $schedule = self::schedule();

        if ($schedule === []) {
            return 0;
        }

        return $schedule[min($retryCount - 1, count($schedule) - 1)];
    }
}
