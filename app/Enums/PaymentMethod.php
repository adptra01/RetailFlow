<?php

namespace App\Enums;

/**
 * Metode pembayaran (FR-05).
 *
 * Nilai enum = nilai yang tersimpan di `transactions.payment_method`.
 * Nilai lama ('cash', 'transfer', 'debit_card', 'credit_card') tidak berubah,
 * sehingga enum ini additive. Pembacaan data lama/impor yang memuat nilai di
 * luar katalog harus tetap aman: di lapisan tampilan gunakan
 * `PaymentMethod::labelFor()` yang fallback ke string mentah, bukan
 * `PaymentMethod::from()`.
 *
 * Fase 5 baru mencakup QRIS statis. Metode gateway (QRIS dinamis, e-wallet)
 * ditambahkan saat integrasi resmi tersedia (§FR-05), bukan sekarang.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';

    case Transfer = 'transfer';

    case DebitCard = 'debit_card';

    case CreditCard = 'credit_card';

    /** QRIS statis milik merchant — hanya instruksi pembayaran (FR-05). */
    case QrisStatic = 'qris_static';

    /**
     * Pembayaran campuran (FR-05). BUKAN metode yang dipilih kasir secara
     * langsung: nilai ini hanya dihasilkan `CreateSale` saat satu penjualan
     * dibayar dengan dua metode atau lebih. Rinciannya tersimpan per tender di
     * `transaction_payments`. Karena itu ia harus dikecualikan dari daftar
     * pilihan metode tunggal di UI.
     */
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Transfer => 'Transfer Bank',
            self::DebitCard => 'Kartu Debit',
            self::CreditCard => 'Kartu Kredit',
            self::QrisStatic => 'QRIS (statis)',
            self::Mixed => 'Campuran',
        };
    }

    /**
     * Metode yang boleh dipilih kasir sebagai SATU-satunya metode pembayaran.
     * `Mixed` dikecualikan karena ia adalah hasil komposisi, bukan pilihan.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $method): bool => $method !== self::Mixed,
        ));
    }

    /**
     * Transfer bank manual dan QRIS statis hanyalah INSTRUKSI pembayaran yang
     * dicatat kasir (FR-05). Sistem tidak boleh menyatakannya terverifikasi
     * hanya karena kasir menekan tombol "dibayar".
     */
    public function isInstructionOnly(): bool
    {
        return match ($this) {
            self::Transfer, self::QrisStatic => true,
            default => false,
        };
    }

    public function isCash(): bool
    {
        return $this === self::Cash;
    }

    /**
     * Hanya tunai yang menerima kelebihan bayar dan memberikan kembalian;
     * metode lain harus tepat sejumlah total (tidak ada kembalian).
     */
    public function allowsChange(): bool
    {
        return $this->isCash();
    }

    /**
     * Label aman untuk nilai apa pun (termasuk nilai lama di luar katalog):
     * fallback ke string mentah, tidak pernah melempar.
     */
    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::tryFrom($value)?->label() ?? $value;
    }
}
