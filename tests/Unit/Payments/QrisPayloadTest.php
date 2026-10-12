<?php

namespace Tests\Unit\Payments;

use App\Exceptions\Payments\InvalidQrisPayload;
use App\Support\Payments\QrisPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\QrisPayloadFactory;

/**
 * Validator payload QRIS statis (FR-05) — memastikan hanya QRIS statis
 * Indonesia yang sah yang boleh disimpan sebagai konfigurasi tenant.
 */
class QrisPayloadTest extends TestCase
{
    public function test_it_accepts_a_valid_static_payload_and_reads_its_fields(): void
    {
        $payload = QrisPayloadFactory::static('TOKO MAKMUR JAYA', 'BANDUNG');

        $parsed = QrisPayload::parse($payload);

        $this->assertSame($payload, $parsed->raw());
        $this->assertSame('TOKO MAKMUR JAYA', $parsed->merchantName());
        $this->assertSame('BANDUNG', $parsed->merchantCity());
        $this->assertSame('360', $parsed->currencyCode());
        $this->assertSame('ID', $parsed->countryCode());
        $this->assertTrue($parsed->isStatic());
        $this->assertSame(hash('sha256', $payload), $parsed->fingerprint());
    }

    public function test_it_accepts_a_payload_without_the_point_of_initiation_tag(): void
    {
        $payload = QrisPayloadFactory::encode([
            '00' => '01',
            '58' => 'ID',
            '59' => 'TOKO TANPA TAG 01',
            '60' => 'SURABAYA',
        ]);

        $parsed = QrisPayload::parse($payload);

        $this->assertTrue($parsed->isStatic());
        $this->assertSame('TOKO TANPA TAG 01', $parsed->merchantName());
    }

    public function test_it_ignores_line_breaks_and_surrounding_whitespace(): void
    {
        $payload = QrisPayloadFactory::static();
        $mangled = "  \n".substr($payload, 0, 20)."\n".substr($payload, 20)."\t ";

        $this->assertSame($payload, QrisPayload::parse($mangled)->raw());
    }

    public function test_it_rejects_an_empty_payload(): void
    {
        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse("  \n ");
    }

    public function test_it_rejects_a_payload_that_is_too_long(): void
    {
        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse(str_repeat('00', QrisPayload::MAX_LENGTH));
    }

    public function test_it_rejects_a_truncated_tlv_block(): void
    {
        $this->expectException(InvalidQrisPayload::class);

        // Tag 59 mengaku 5 karakter tetapi hanya tersedia 3.
        QrisPayload::parse('0002015905ABC');
    }

    public function test_it_rejects_a_non_numeric_length_field(): void
    {
        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse('00AB');
    }

    public function test_it_rejects_a_payload_whose_format_indicator_is_wrong(): void
    {
        $payload = QrisPayloadFactory::encode([
            '00' => '02',
            '58' => 'ID',
            '59' => 'TOKO',
            '60' => 'JAKARTA',
        ]);

        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse($payload);
    }

    public function test_it_rejects_a_dynamic_payload(): void
    {
        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse(QrisPayloadFactory::dynamic());
    }

    public function test_it_rejects_a_non_indonesian_payload(): void
    {
        $payload = QrisPayloadFactory::encode([
            '00' => '01',
            '58' => 'SG',
            '59' => 'MERCHANT SG',
            '60' => 'SINGAPORE',
        ]);

        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse($payload);
    }

    public function test_it_rejects_a_missing_checksum(): void
    {
        $payload = QrisPayloadFactory::encode([
            '00' => '01',
            '58' => 'ID',
            '59' => 'TOKO',
            '60' => 'JAKARTA',
        ]);

        // Buang blok CRC "6304xxxx" di ujung.
        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse(substr($payload, 0, -8));
    }

    public function test_it_rejects_a_wrong_checksum(): void
    {
        $payload = QrisPayloadFactory::static('TOKO', 'JAKARTA');
        $tampered = substr($payload, 0, -4).'0000';

        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse($tampered);
    }

    public function test_is_valid_reports_the_same_verdict_without_throwing(): void
    {
        $this->assertTrue(QrisPayload::isValid(QrisPayloadFactory::static()));
        $this->assertFalse(QrisPayload::isValid(QrisPayloadFactory::dynamic()));
        $this->assertFalse(QrisPayload::isValid('not-a-qris'));
    }

    /**
     * Vektor uji baku CRC-16/CCITT-FALSE: "123456789" → 0x29B1.
     */
    public function test_crc16_matches_the_standard_test_vector(): void
    {
        $this->assertSame('29B1', QrisPayload::crc16('123456789'));
    }

    /**
     * @param  array<string, string>  $tags
     */
    #[DataProvider('malformedPayloads')]
    public function test_it_rejects_malformed_structures(array $tags): void
    {
        $this->expectException(InvalidQrisPayload::class);

        QrisPayload::parse(QrisPayloadFactory::encode($tags));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'missing format indicator' => [['58' => 'ID', '59' => 'TOKO']],
            'missing country' => [['00' => '01', '59' => 'TOKO']],
            'empty payload' => [[]],
        ];
    }
}
