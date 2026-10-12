<?php

namespace App\Support\Sync;

/**
 * Ringkasan hasil satu putaran penarikan perubahan server (§11.3 langkah
 * 9–10).
 */
final readonly class SyncPullSummary
{
    public function __construct(
        /** Perubahan yang diterapkan pada basis data lokal. */
        public int $applied = 0,
        /** Perubahan yang dilewati (milik perangkat ini / sudah sama / tidak dicerminkan). */
        public int $skipped = 0,
        /** Perubahan yang tidak diterapkan karena bentrok dengan suntingan lokal yang lebih baru. */
        public int $conflicted = 0,
        /** Jumlah halaman yang ditarik. */
        public int $pages = 0,
        /** Kursor setelah putaran ini; null bila server belum punya perubahan. */
        public ?string $cursor = null,
        /** True bila masih ada halaman berikutnya yang belum ditarik. */
        public bool $hasMore = false,
    ) {}

    public function total(): int
    {
        return $this->applied + $this->skipped + $this->conflicted;
    }

    /**
     * @return array<string, int|string|null|bool>
     */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'skipped' => $this->skipped,
            'conflicted' => $this->conflicted,
            'pages' => $this->pages,
            'cursor' => $this->cursor,
            'has_more' => $this->hasMore,
        ];
    }
}
