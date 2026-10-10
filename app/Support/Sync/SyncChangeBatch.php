<?php

namespace App\Support\Sync;

/**
 * Satu halaman perubahan server untuk sinkronisasi tarik (§11.3 langkah 9).
 *
 * `cursor` adalah penanda posisi; `changes` adalah daftar perubahan yang
 * kompatibel untuk diterapkan perangkat. Seam ini memisahkan transport
 * (item Sprint 4.3) dari strategi kursor (item Sprint 4.4).
 */
final readonly class SyncChangeBatch
{
    /**
     * @param  list<array<string, mixed>>  $changes
     */
    public function __construct(
        public ?string $cursor,
        public array $changes = [],
        public bool $hasMore = false,
    ) {}
}
