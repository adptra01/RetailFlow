<?php

namespace App\Support\Qr;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Render QR ke SVG memakai `bacon/bacon-qr-code` yang SUDAH ada sebagai
 * dependensi Fortify (2FA) — tidak ada paket baru, dan tidak butuh ekstensi
 * GD/Imagick karena keluarannya SVG.
 *
 * Dipakai untuk memajang QRIS statis merchant di POS dan pratinjau di
 * pengaturan pembayaran.
 */
final class SvgQrCode
{
    /**
     * @param  string  $text  muatan QR (untuk QRIS: payload EMVCo apa adanya)
     * @param  int  $size  sisi gambar dalam piksel
     * @param  int  $margin  quiet zone dalam modul (QRIS menyarankan >= 4; UI memberi padding sendiri, jadi 0 boleh)
     */
    public static function render(string $text, int $size = 320, int $margin = 0): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(
                    $size,
                    $margin,
                    null,
                    null,
                    Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(24, 24, 27)),
                ),
                new SvgImageBackEnd,
            ),
        );

        $svg = $writer->writeString($text);

        // Buang deklarasi XML agar bisa disisipkan langsung ke dalam HTML
        // (pola yang sama dipakai Fortify).
        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * SVG sebagai data URI — untuk dipakai pada `<img src>` bila perlu.
     */
    public static function dataUri(string $text, int $size = 320, int $margin = 0): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::render($text, $size, $margin));
    }
}
