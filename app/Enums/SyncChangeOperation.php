<?php

namespace App\Enums;

/**
 * Jenis perubahan yang dicatat pada jurnal sinkronisasi server (§11.3
 * langkah 9–10). Dipakai sebagai `operation` pada `sync_changes`.
 */
enum SyncChangeOperation: string
{
    case Created = 'created';

    case Updated = 'updated';

    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Dibuat',
            self::Updated => 'Diubah',
            self::Deleted => 'Dihapus',
        };
    }
}
