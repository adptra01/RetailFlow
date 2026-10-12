<?php

namespace App\Enums;

use App\Payments\ProviderPaymentStatus;

/**
 * Status verifikasi pembayaran (FR-05).
 *
 * Dipisahkan dari `payment_status` (paid/credit) yang menggambarkan
 * penyelesaian transaksi. Kolom ini menjawab "siapa yang menyatakan uang
 * sudah masuk":
 *
 *   - cashier_recorded        "dicatat kasir" — klaim kasir, belum terverifikasi;
 *   - awaiting_verification   "menunggu verifikasi" (gateway dipicu);
 *   - verified                "terverifikasi" (kembali dari gateway resmi);
 *   - failed                  "gagal";
 *   - cancelled               "dibatalkan";
 *   - refunded                "direfund";
 *   - partially_refunded      "direfund sebagian".
 *
 * Fase 5 irisan QRIS statis TIDAK punya jalur menuju `verified`: QRIS statis
 * hanya instruksi pembayaran (FR-05). `verified` baru terisi lewat integrasi
 * gateway resmi (webhook + tanda tangan + rekonsiliasi) pada irisan
 * berikutnya.
 *
 * Pemetaan ke istilah plan §14 (Mode B — Gateway), yang menyebut status
 * `reported_by_cashier` dan `pending`: `cashier_recorded` ≡
 * `reported_by_cashier`, `awaiting_verification` ≡ `pending`. Nama di sini
 * dipilih sekali dan konsisten antara API dan database seperti disyaratkan
 * plan; lihat `forProviderStatus()` untuk jembatan dari status penyedia.
 */
enum PaymentVerification: string
{
    case CashierRecorded = 'cashier_recorded';

    case AwaitingVerification = 'awaiting_verification';

    case Verified = 'verified';

    case Failed = 'failed';

    case Cancelled = 'cancelled';

    case Refunded = 'refunded';

    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::CashierRecorded => 'Dicatat kasir',
            self::AwaitingVerification => 'Menunggu verifikasi',
            self::Verified => 'Terverifikasi',
            self::Failed => 'Gagal',
            self::Cancelled => 'Dibatalkan',
            self::Refunded => 'Direfund',
            self::PartiallyRefunded => 'Direfund sebagian',
        };
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Apakah status lokal sudah "selesai" dan tidak boleh diturunkan kembali
     * oleh peristiwa penyedia yang datang terlambat.
     *
     * Callback/webhook dapat tiba di luar urutan (mis. `pending` yang dikirim
     * ulang setelah `paid`). Tanpa penjaga ini, replay semacam itu akan
     * membatalkan verifikasi pembayaran yang sudah sah (§15).
     *
     * Sengaja BUKAN "terminal": `failed` masih boleh berubah menjadi `verified`
     * bila pelanggan berhasil membayar pada percobaan berikutnya.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Verified, self::Refunded, self::PartiallyRefunded => true,
            self::CashierRecorded, self::AwaitingVerification, self::Failed, self::Cancelled => false,
        };
    }

    /**
     * Apakah status lokal boleh berpindah ke `$next` berdasarkan peristiwa
     * penyedia (plan §14/§15: callback dapat tiba tidak berurutan atau dikirim
     * ulang).
     *
     * Aturan: selama pembayaran belum "selesai" (`isSettled()`), semua arah
     * transisi sah — pelanggan boleh gagal lalu berhasil. Setelah final, hanya
     * penyelesaian yang lebih jauh yang diterima (`verified` → refund), dan
     * `refunded` tidak pernah dikembalikan ke status lain. Transisi yang
     * ditolak diabaikan, bukan diterapkan diam-diam (§17.4: pembatalan
     * memerlukan alasan dan izin, bukan efek samping callback).
     */
    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        if (! $this->isSettled()) {
            return true;
        }

        return match ($this) {
            self::Verified => in_array($next, [self::Refunded, self::PartiallyRefunded], true),
            self::Refunded, self::PartiallyRefunded => false,
            default => true,
        };
    }

    /**
     * Warna badge Flux untuk status ini.
     */
    public function color(): string
    {
        return match ($this) {
            self::CashierRecorded => 'zinc',
            self::AwaitingVerification => 'amber',
            self::Verified => 'green',
            self::Failed => 'rose',
            self::Cancelled => 'zinc',
            self::Refunded => 'purple',
            self::PartiallyRefunded => 'purple',
        };
    }

    /**
     * Jembatan dari status penyedia (Mode B — Gateway, plan §14) ke kosakata
     * domain (FR-05).
     *
     * Mengembalikan `null` untuk status penyedia yang tidak dikenali: pemanggil
     * TIDAK BOLEH menafsirkan hasil null sebagai pembayaran berhasil — biarkan
     * status lokal apa adanya dan serahkan ke rekonsiliasi.
     */
    public static function forProviderStatus(ProviderPaymentStatus $status): ?self
    {
        return match ($status) {
            ProviderPaymentStatus::Pending => self::AwaitingVerification,
            ProviderPaymentStatus::Paid => self::Verified,
            ProviderPaymentStatus::Failed => self::Failed,
            ProviderPaymentStatus::Cancelled => self::Cancelled,
            ProviderPaymentStatus::Refunded => self::Refunded,
            ProviderPaymentStatus::PartiallyRefunded => self::PartiallyRefunded,
            ProviderPaymentStatus::Unknown => null,
        };
    }

    /** Label aman untuk nilai apa pun (fallback ke string mentah). */
    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::tryFrom($value)?->label() ?? $value;
    }
}
