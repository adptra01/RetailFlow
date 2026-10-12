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

    /**
     * Perubahan server yang lebih tua daripada suntingan lokal yang belum
     * terkirim (§11.3 langkah 11). Data lokal tidak ditimpa diam-diam;
     * konflik ditampilkan agar pengguna berwenang memutuskan.
     */
    case Data = 'data';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Konflik stok',
            self::Data => 'Konflik data',
        };
    }
}
