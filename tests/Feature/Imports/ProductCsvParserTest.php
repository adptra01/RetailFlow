<?php

namespace Tests\Feature\Imports;

use App\Support\Imports\ProductCsvParser;
use Tests\TestCase;

class ProductCsvParserTest extends TestCase
{
    private ProductCsvParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ProductCsvParser;
    }

    public function test_parses_english_headers_into_canonical_rows(): void
    {
        $parsed = $this->parser->parse(
            "name,sku,barcode,category,price,buy_price,stock,min_stock,is_active\n"
            ."Kopi Arabica 250g,KOPI-AR-250,8991001001001,Minuman,45000,38000,50,10,1\n"
        );

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('Kopi Arabica 250g', $parsed['rows'][0]['name']);
        $this->assertSame('KOPI-AR-250', $parsed['rows'][0]['sku']);
        $this->assertSame('45000', $parsed['rows'][0]['price']);
        $this->assertSame('38000', $parsed['rows'][0]['buy_price']);
        $this->assertSame(2, $parsed['rows'][0]['line']);
    }

    public function test_accepts_indonesian_header_aliases_and_semicolon_delimiter(): void
    {
        $parsed = $this->parser->parse(
            "nama;kode;harga;stok;aktif\n"
            ."Teh Melati;TEH-01;5000;20;1\n"
        );

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('Teh Melati', $parsed['rows'][0]['name']);
        $this->assertSame('TEH-01', $parsed['rows'][0]['sku']);
        $this->assertSame('5000', $parsed['rows'][0]['price']);
        $this->assertSame('20', $parsed['rows'][0]['stock']);
        $this->assertSame('1', $parsed['rows'][0]['is_active']);
    }

    public function test_handles_quoted_fields_with_commas(): void
    {
        $parsed = $this->parser->parse(
            "name,sku,price,stock\n"
            ."\"Teh, Celup Premium\",TEA-1,5000,10\n"
        );

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('Teh, Celup Premium', $parsed['rows'][0]['name']);
        $this->assertSame(2, $parsed['rows'][0]['line']);
    }

    public function test_skips_blank_and_comment_lines(): void
    {
        $parsed = $this->parser->parse(
            "name,price\n"
            ."# contoh baris\n"
            ."Kopi,45000\n"
            ."\n"
            ."Teh,5000\n"
        );

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame(3, $parsed['rows'][0]['line']);
        $this->assertSame(5, $parsed['rows'][1]['line']);
    }

    public function test_reports_row_with_wrong_column_count_and_excludes_it(): void
    {
        $parsed = $this->parser->parse(
            "name,price,stock\n"
            ."Rusak,1000\n"
            ."Benar,2000,5\n"
        );

        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('Benar', $parsed['rows'][0]['name']);
        $this->assertStringContainsString('Baris 2', $parsed['errors'][0]);
    }

    public function test_missing_required_header_returns_file_error(): void
    {
        $parsed = $this->parser->parse(
            "name,stok\n"
            ."Kopi,5\n"
        );

        $this->assertSame([], $parsed['rows']);
        $this->assertStringContainsString('name` dan `price', $parsed['errors'][0]);
    }

    public function test_empty_file_returns_error(): void
    {
        $parsed = $this->parser->parse("\n\n");

        $this->assertSame([], $parsed['rows']);
        $this->assertStringContainsString('Berkas kosong', $parsed['errors'][0]);
    }

    public function test_unknown_and_duplicate_headers_are_reported_but_parse_continues(): void
    {
        $parsed = $this->parser->parse(
            "name,notes,price,price\n"
            ."Kopi,x,45000\n"
        );

        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('Kopi', $parsed['rows'][0]['name']);
        $this->assertSame('x', $parsed['rows'][0]['notes']);
        $this->assertTrue(collect($parsed['errors'])->contains(fn ($e) => str_contains($e, 'notes')));
        $this->assertTrue(collect($parsed['errors'])->contains(fn ($e) => str_contains($e, 'duplikat')));
    }

    public function test_template_contains_headers_and_commented_example(): void
    {
        $template = ProductCsvParser::template();

        $this->assertStringContainsString('name,sku,barcode,category,price', $template);
        $this->assertStringContainsString('#', $template);
    }
}