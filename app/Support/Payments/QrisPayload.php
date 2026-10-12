<?php

namespace App\Support\Payments;

use App\Exceptions\Payments\InvalidQrisPayload;

/**
 * Parser & validator payload QRIS statis (EMVCo TLV), FR-05.
 *
 * Tujuan: memastikan yang diunggah pemilik benar-benar string QRIS statis
 * Indonesia yang utuh — bukan gambar, bukan QRIS dinamis, bukan hasil salin
 * yang rusak. Struktur EMVCo: serangkaian TLV (tag 2 digit, panjang 2 digit,
 * nilai sepanjang `panjang`).
 *
 * Tag yang diperiksa:
 *   00  payload format indicator  → wajib "01"
 *   01  point of initiation       → "11" statis (boleh absen), "12" dinamis (ditolak)
 *   53  currency                  → "360" untuk IDR (informatif)
 *   58  country code              → wajib "ID"
 *   59  merchant name             → diambil untuk tampilan
 *   60  merchant city             → informatif
 *   63  CRC-16/CCITT-FALSE        → wajib ada & cocok
 *
 * Kelas ini murni (tanpa I/O) sehingga mudah diuji dan dipakai ulang oleh
 * aksi domain maupun lapisan tampilan.
 */
final class QrisPayload
{
    public const MAX_LENGTH = 512;

    private const TAG_PAYLOAD_FORMAT = '00';

    private const TAG_POINT_OF_INITIATION = '01';

    private const TAG_CURRENCY = '53';

    private const TAG_COUNTRY = '58';

    private const TAG_MERCHANT_NAME = '59';

    private const TAG_MERCHANT_CITY = '60';

    private const TAG_CRC = '63';

    /**
     * @param  array<string, string>  $tags
     */
    private function __construct(
        private readonly string $raw,
        private readonly array $tags,
    ) {}

    /**
     * Parsing + validasi penuh. Melempar InvalidQrisPayload bila tidak sah.
     */
    public static function parse(string $payload): self
    {
        $normalized = self::normalize($payload);

        if ($normalized === '') {
            throw InvalidQrisPayload::empty();
        }

        if (strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidQrisPayload::tooLong(self::MAX_LENGTH);
        }

        $tags = self::parseTlv($normalized);

        if (($tags[self::TAG_PAYLOAD_FORMAT] ?? null) !== '01') {
            throw InvalidQrisPayload::notPayloadFormat();
        }

        if (isset($tags[self::TAG_POINT_OF_INITIATION]) && $tags[self::TAG_POINT_OF_INITIATION] === '12') {
            throw InvalidQrisPayload::dynamicNotSupported();
        }

        if (($tags[self::TAG_COUNTRY] ?? null) !== 'ID') {
            throw InvalidQrisPayload::notIndonesia();
        }

        self::assertCrc($normalized, $tags);

        return new self($normalized, $tags);
    }

    /**
     * Validasi tanpa melempar (untuk preview/UI).
     */
    public static function isValid(string $payload): bool
    {
        try {
            self::parse($payload);

            return true;
        } catch (InvalidQrisPayload) {
            return false;
        }
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function merchantName(): ?string
    {
        $name = $this->tags[self::TAG_MERCHANT_NAME] ?? null;

        return $name === null || $name === '' ? null : $name;
    }

    public function merchantCity(): ?string
    {
        $city = $this->tags[self::TAG_MERCHANT_CITY] ?? null;

        return $city === null || $city === '' ? null : $city;
    }

    public function currencyCode(): ?string
    {
        return $this->tags[self::TAG_CURRENCY] ?? null;
    }

    public function countryCode(): ?string
    {
        return $this->tags[self::TAG_COUNTRY] ?? null;
    }

    public function isStatic(): bool
    {
        return ($this->tags[self::TAG_POINT_OF_INITIATION] ?? '11') !== '12';
    }

    /**
     * Sidik jari payload untuk metadata audit — payload penuh tidak perlu
     * disimpan di jejak audit.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->raw);
    }

    /**
     * Buang CR/LF/tab (aman: TLV yang sah tidak pernah memuatnya) dan spasi
     * di ujung. Spasi di dalam nilai TIDAK dibuang karena panjang TLV
     * menghitung byte apa adanya.
     */
    public static function normalize(string $payload): string
    {
        return trim(str_replace(["\r", "\n", "\t"], '', $payload));
    }

    /**
     * @return array<string, string>
     */
    private static function parseTlv(string $payload): array
    {
        $tags = [];
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            if ($offset + 4 > $length) {
                throw InvalidQrisPayload::malformed('blok TLV terpotong di posisi '.$offset);
            }

            $tag = substr($payload, $offset, 2);
            $lengthRaw = substr($payload, $offset + 2, 2);

            if (! ctype_digit($tag) || ! ctype_digit($lengthRaw)) {
                throw InvalidQrisPayload::malformed('tag/panjang bukan angka di posisi '.$offset);
            }

            $valueLength = (int) $lengthRaw;
            $valueStart = $offset + 4;
            $valueEnd = $valueStart + $valueLength;

            if ($valueEnd > $length) {
                throw InvalidQrisPayload::malformed('nilai tag '.$tag.' melewati akhir string');
            }

            $tags[$tag] = substr($payload, $valueStart, $valueLength);
            $offset = $valueEnd;
        }

        return $tags;
    }

    /**
     * @param  array<string, string>  $tags
     */
    private static function assertCrc(string $payload, array $tags): void
    {
        $expected = $tags[self::TAG_CRC] ?? null;

        if ($expected === null) {
            throw InvalidQrisPayload::missingCrc();
        }

        // CRC dihitung atas seluruh isi termasuk "6304", jadi cari blok CRC
        // terakhir secara eksplisit alih-alih menebak dari offset.
        $marker = self::TAG_CRC.'04';
        $position = strrpos($payload, $marker);

        if ($position === false) {
            throw InvalidQrisPayload::badCrc();
        }

        $computed = self::crc16(substr($payload, 0, $position + strlen($marker)));

        if (strcasecmp($computed, $expected) !== 0) {
            throw InvalidQrisPayload::badCrc();
        }
    }

    /**
     * CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF, tanpa refleksi/xor akhir).
     * Inilah varian yang dipakai spesifikasi EMVCo QRIS.
     */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000)
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
