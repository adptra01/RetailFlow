<?php

namespace Tests\Support;

use App\Support\Payments\QrisPayload;

/**
 * Pembuat payload QRIS untuk pengujian (bukan kode produksi).
 *
 * Menyusun TLV EMVCo apa adanya lalu menambahkan tag 63 + CRC-16 yang benar,
 * sehingga tes dapat memvariasikan bagian mana pun (kode negara, tag 01,
 * checksum) secara terkendali.
 */
final class QrisPayloadFactory
{
    /**
     * QRIS statis yang sah. Tag 26 (merchant account information) memuat TLV
     * bersarang seperti QRIS sungguhan agar parser diuji terhadap struktur asli.
     *
     * @param  array<string, string>  $tags  timpa/tingkatkan tag tingkat atas
     */
    public static function static(
        string $merchantName = 'TOKO CONTOH',
        string $city = 'JAKARTA',
        array $tags = [],
    ): string {
        $nested = self::nested('00', 'ID.CO.QRIS.WWW')
            .self::nested('01', '936000420000000000')
            .self::nested('02', '936000420000000001')
            .self::nested('03', 'UMI');

        // array_replace (bukan array_merge): array_merge menomori ulang kunci
        // numerik, sehingga tag '26'/'53'/… akan hilang menjadi 0/1/…
        return self::encode(array_replace([
            '00' => '01',
            '01' => '11',
            '26' => $nested,
            '53' => '360',
            '58' => 'ID',
            '59' => $merchantName,
            '60' => $city,
        ], $tags));
    }

    /**
     * QRIS dinamis (harus ditolak sebagai konfigurasi statis).
     */
    public static function dynamic(string $merchantName = 'TOKO CONTOH'): string
    {
        return self::encode([
            '00' => '01',
            '01' => '12',
            '53' => '360',
            '58' => 'ID',
            '59' => $merchantName,
            '60' => 'JAKARTA',
        ]);
    }

    /**
     * Susun TLV induk + tag 63 (CRC opsional ditimpa untuk kasus rusak).
     *
     * @param  array<string, string>  $tags
     */
    public static function encode(array $tags, ?string $crc = null): string
    {
        $body = '';

        foreach ($tags as $tag => $value) {
            // Kunci array PHP mengubah '26' menjadi int 26; pad kembali ke
            // 2 digit agar tag EMVCo tetap utuh.
            $tag = str_pad((string) $tag, 2, '0', STR_PAD_LEFT);

            $body .= $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
        }

        $withCrcTag = $body.'6304';

        return $withCrcTag.($crc ?? QrisPayload::crc16($withCrcTag));
    }

    /**
     * Banyak "tag panjang" menjadi satu nilai TLV bersarang.
     */
    private static function nested(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }
}
