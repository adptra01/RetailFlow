<?php

namespace App\Enums;

/**
 * Status operasi yang diterima server pada `sync_inbox` (§11.3).
 *
 * Server menyimpan operasi yang sah dalam transaksi; status ini menandai
 * sejauh mana operasi diproses terhadap domain. `Received` = tersimpan
 * durable dan menunggu penerapan; item Sprint 4 berikutnya menambahkan
 * penerapan domain, deteksi konflik, dan penolakan.
 */
enum SyncInboxStatus: string
{
    case Received = 'received';

    case Applied = 'applied';

    case Rejected = 'rejected';

    case Conflict = 'conflict';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Diterima',
            self::Applied => 'Diterapkan',
            self::Rejected => 'Ditolak',
            self::Conflict => 'Konflik',
        };
    }
}
