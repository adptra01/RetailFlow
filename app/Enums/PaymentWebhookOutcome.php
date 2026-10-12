<?php

namespace App\Enums;

/**
 * Hasil pemrosesan satu callback/webhook pembayaran (plan §14).
 *
 * Callback selalu dijawab `2xx` bila tanda tangannya sah — termasuk ketika
 * isinya tidak dapat diterapkan — agar penyedia tidak mengirim ulang
 * selamanya. Justru karena itu hasilnya harus tercatat eksplisit:
 *
 *   - `Received`  — peristiwa sah tersimpan, tetapi pemrosesan belum selesai
 *                   (mis. proses terputus). Sisa seperti ini layak diperiksa
 *                   manual; TIDAK boleh dibaca sebagai pembayaran berhasil;
 *   - `Applied`   — status penyedia diterapkan ke penjualan lokal;
 *   - `Duplicate` — peristiwa yang sama sudah pernah diterima (§14 idempotensi);
 *   - `Ignored`   — peristiwa sah tetapi tidak mengubah apa pun: status tidak
 *                   dikenali, atau peristiwa lama yang akan menurunkan status;
 *   - `Unmatched` — reference ID provider tidak dikenal pada instalasi ini.
 *
 * `Received`, `Ignored`, dan `Unmatched` BUKAN pembayaran berhasil.
 */
enum PaymentWebhookOutcome: string
{
    case Received = 'received';

    case Applied = 'applied';

    case Duplicate = 'duplicate';

    case Ignored = 'ignored';

    case Unmatched = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Diterima',
            self::Applied => 'Diterapkan',
            self::Duplicate => 'Duplikat',
            self::Ignored => 'Diabaikan',
            self::Unmatched => 'Referensi tidak dikenal',
        };
    }

    /** Apakah callback ini mengubah status pembayaran lokal. */
    public function isEffective(): bool
    {
        return $this === self::Applied;
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
