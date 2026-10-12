<?php

namespace App\Enums;

/**
 * Hasil pemeriksaan ulang satu charge pada satu putaran rekonsiliasi
 * (plan §14 "Rekonsiliasi", "Penanganan timeout").
 *
 *   - `Changed`   — penyedia melaporkan status baru yang sah; diterapkan ke
 *                   charge (+ penjualan bila perlu);
 *   - `Unchanged` — status penyedia sama dengan catatan lokal;
 *   - `Timeout`   — penyedia tidak menjawab / tidak dikenal. §14: timeout BUKAN
 *                   berarti gagal — hasilnya "tidak diketahui" dan charge tetap
 *                   direkonsiliasi pada putaran berikutnya. TIDAK PERNAH
 *                   dianggap pembayaran berhasil;
 *   - `Skipped`   — penyedia melaporkan transisi yang tidak sah menurut aturan
 *                   domain (mis. mencabut pembayaran yang sudah final). Dibuat
 *                   untuk pemeriksaan manual, bukan untuk diterapkan diam-diam.
 *
 * `Changed` satu-satunya yang mengubah status pembayaran lokal.
 */
enum PaymentReconciliationOutcome: string
{
    case Changed = 'changed';

    case Unchanged = 'unchanged';

    case Timeout = 'timeout';

    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Changed => 'Berubah',
            self::Unchanged => 'Sama',
            self::Timeout => 'Tidak dijawab',
            self::Skipped => 'Dilewati',
        };
    }

    public function isEffective(): bool
    {
        return $this === self::Changed;
    }

    /** Aman untuk nilai apa pun (fallback ke string mentah). */
    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::tryFrom($value)?->label() ?? $value;
    }
}
