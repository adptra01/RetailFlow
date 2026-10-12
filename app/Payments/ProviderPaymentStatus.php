<?php

namespace App\Payments;

/**
 * Status pembayaran pada tingkat penyedia (Mode B — Gateway, plan §14).
 *
 * Ini adalah kosakata NETRAL milik port: setiap adaptor penyedia
 * menerjemahkan status aslinya (yang berbeda-beda antar penyedia) ke enum ini,
 * lalu `PaymentVerification::forProviderStatus()` memetakannya ke kosakata
 * domain kita (FR-05). Dengan begitu logika bisnis tidak pernah bergantung
 * pada status spesifik satu vendor.
 */
enum ProviderPaymentStatus: string
{
    /** Dibuat, menunggu pembayaran. */
    case Pending = 'pending';

    /** Dibayar — inilah satu-satunya status yang boleh menjadi `verified`. */
    case Paid = 'paid';

    case Failed = 'failed';

    case Cancelled = 'cancelled';

    case Refunded = 'refunded';

    case PartiallyRefunded = 'partially_refunded';

    /**
     * Penyedia tidak memberikan status yang dapat dikenali (mis. respons rusak
     * atau penyedia belum menyediakan informasi). SECARA SENGAJA bukan `Paid`:
     * status yang tidak diketahui tidak boleh dianggap pembayaran berhasil.
     */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu pembayaran',
            self::Paid => 'Dibayar',
            self::Failed => 'Gagal',
            self::Cancelled => 'Dibatalkan',
            self::Refunded => 'Direfund',
            self::PartiallyRefunded => 'Direfund sebagian',
            self::Unknown => 'Tidak diketahui',
        };
    }

    /**
     * Status akhir: tidak akan berubah lagi tanpa aksi baru (mis. refund).
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
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
