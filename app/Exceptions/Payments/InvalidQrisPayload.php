<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Payload QRIS statis tidak valid (FR-05).
 *
 * Pesan sengaja dalam bahasa Indonesia karena ditampilkan langsung di UI
 * pengaturan pembayaran (pemilik toko yang mengunggah QRIS).
 */
final class InvalidQrisPayload extends RuntimeException
{
    public static function empty(): self
    {
        return new self('Isi string QRIS terlebih dahulu.');
    }

    public static function tooLong(int $max): self
    {
        return new self("String QRIS terlalu panjang (maksimal {$max} karakter).");
    }

    public static function malformed(string $reason): self
    {
        return new self("Format QRIS tidak dikenali: {$reason}.");
    }

    public static function notPayloadFormat(): self
    {
        return new self('Bukan QRIS yang sah: penanda format payload (tag 00) tidak sesuai.');
    }

    public static function notIndonesia(): self
    {
        return new self('Bukan QRIS Indonesia: kode negara (tag 58) harus "ID".');
    }

    public static function dynamicNotSupported(): self
    {
        return new self('Ini QRIS dinamis (tag 01 = 12). Yang boleh disimpan pada pengaturan ini hanya QRIS statis.');
    }

    public static function missingCrc(): self
    {
        return new self('Checksum QRIS (tag 63) tidak ditemukan.');
    }

    public static function badCrc(): self
    {
        return new self('Checksum QRIS tidak cocok — kemungkinan salah salin atau QR rusak.');
    }
}
