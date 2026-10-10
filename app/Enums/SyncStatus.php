<?php

namespace App\Enums;

/**
 * Status operasi sinkronisasi (§11.2). Append-only kecuali transisi status
 * yang dikendalikan server (§11.3).
 */
enum SyncStatus: string
{
    case Pending = 'pending';

    case InFlight = 'in_flight';

    case Synced = 'synced';

    case Conflict = 'conflict';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::InFlight => 'Dikirim',
            self::Synced => 'Tersinkron',
            self::Conflict => 'Konflik',
            self::Failed => 'Gagal',
        };
    }

    /**
     * Status terminal: tidak perlu diproses lagi.
     */
    public function isTerminal(): bool
    {
        return $this === self::Synced;
    }

    /**
     * Status yang masih bisa dikirim ulang (pending/failed).
     *
     * @return list<string>
     */
    public static function retryableValues(): array
    {
        return [self::Pending->value, self::Failed->value];
    }
}
