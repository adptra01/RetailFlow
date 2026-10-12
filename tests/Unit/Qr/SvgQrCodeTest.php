<?php

namespace Tests\Unit\Qr;

use App\Support\Qr\SvgQrCode;
use PHPUnit\Framework\TestCase;

/**
 * Renderer QR SVG (bacon/bacon-qr-code yang sudah ada via Fortify).
 */
class SvgQrCodeTest extends TestCase
{
    public function test_it_renders_inline_svg_markup(): void
    {
        $svg = SvgQrCode::render('HELLO-QRIS');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
    }

    public function test_it_omits_the_xml_declaration_so_it_can_be_inlined(): void
    {
        $this->assertStringNotContainsString('<?xml', SvgQrCode::render('HELLO-QRIS'));
    }

    public function test_it_honours_the_requested_size(): void
    {
        $svg = SvgQrCode::render('HELLO-QRIS', 240);

        $this->assertStringContainsString('width="240"', $svg);
        $this->assertStringContainsString('height="240"', $svg);
    }

    public function test_it_is_deterministic_and_input_sensitive(): void
    {
        $this->assertSame(SvgQrCode::render('A'), SvgQrCode::render('A'));
        $this->assertNotSame(SvgQrCode::render('A'), SvgQrCode::render('B'));
    }

    public function test_it_renders_a_base64_data_uri(): void
    {
        $uri = SvgQrCode::dataUri('HELLO-QRIS', 200);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);

        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

        $this->assertIsString($decoded);
        $this->assertStringContainsString('<svg', $decoded);
    }
}
