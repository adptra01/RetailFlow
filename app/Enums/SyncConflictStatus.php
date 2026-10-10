<?php

namespace App\Enums;

/**
 * Siklus hidup kasus konflik sinkronisasi (§11.4, §11.5).
 *
 * - `Open`     — baru terdeteksi, menunggu tindakan pengguna yang berwenang;
 * - `Resolved` — diselesaikan (mis. penjualan ditahan diterapkan ulang
 *                dengan kebijakan stok negatif, atau stok disesuaikan);
 * - `Dismissed`— ditutup tanpa penerapan ulang (mis. transaksi memang batal
 *                di perangkat).
 */
enum SyncConflictStatus: string
{
    case Open = 'open';

    case Resolved = 'resolved';

    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Resolved => 'Selesai',
            self::Dismissed => 'Ditutup',
        };
    }
}
