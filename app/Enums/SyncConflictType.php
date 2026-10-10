<?php

namespace App\Enums;

/**
 * Jenis konflik sinkronisasi (§11.4). Server mencatat konflik sebagai kasus
 * yang perlu diselesaikan, bukan menghapus data perangkat diam-diam.
 */
enum SyncConflictType: string
{
    /**
     * Penjualan perangkat membuat stok server negatif atau melebihi stok
     * yang tersedia (§11.4 "Konflik stok").
     */
    case Stock = 'stock';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Konflik stok',
        };
    }
}
