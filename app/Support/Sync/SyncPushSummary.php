<?php

namespace App\Support\Sync;

/**
 * Ringkasan hasil satu putaran pengiriman outbox (§11.5 — status sinkronisasi).
 */
final readonly class SyncPushSummary
{
    public function __construct(
        /** Jumlah operasi yang dikirim pada putaran ini. */
        public int $sent = 0,
        /** Operasi yang dikonfirmasi server (termasuk duplikat). */
        public int $synced = 0,
        /** Operasi yang ditolak server dan butuh tindakan pengguna. */
        public int $failed = 0,
        /** Operasi yang berkonflik (menunggu penyelesaian). */
        public int $conflicted = 0,
        /** Operasi yang akan diulang dengan backoff (hasil belum diketahui). */
        public int $retrying = 0,
    ) {}

    public function total(): int
    {
        return $this->synced + $this->failed + $this->conflicted + $this->retrying;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'sent' => $this->sent,
            'synced' => $this->synced,
            'failed' => $this->failed,
            'conflicted' => $this->conflicted,
            'retrying' => $this->retrying,
        ];
    }
}
