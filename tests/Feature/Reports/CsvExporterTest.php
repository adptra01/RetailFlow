<?php

namespace Tests\Feature\Reports;

use App\Support\Exports\CsvExporter;
use Tests\TestCase;

class CsvExporterTest extends TestCase
{
    public function test_export_writes_header_and_rows_with_bom(): void
    {
        $csv = CsvExporter::fromRows(
            ['date', 'omzet'],
            [
                ['date' => '2026-10-10', 'omzet' => 30000],
                ['date' => '2026-10-11', 'omzet' => 15000],
            ],
        );

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(['date;omzet', '2026-10-10;30000', '2026-10-11;15000'], $this->lines($csv));
    }

    public function test_quotes_cells_containing_delimiter_quote_or_newline(): void
    {
        $csv = CsvExporter::fromRows(['name', 'note'], [
            ['name' => 'A;B', 'note' => 'say "hi"'],
            ['name' => 'C', 'note' => "line1\nline2"],
        ]);

        $this->assertSame([
            'name;note',
            '"A;B";"say ""hi"""',
            'C;"line1'."\n".'line2"',
        ], $this->lines($csv));
    }

    public function test_missing_key_and_null_are_exported_as_empty(): void
    {
        $csv = CsvExporter::fromRows(['a', 'b'], [
            ['a' => 'x'],
            ['a' => 0, 'b' => null],
        ]);

        $this->assertSame(['a;b', 'x;', '0;'], $this->lines($csv));
    }

    /**
     * @return list<string>
     */
    private function lines(string $csv): array
    {
        $body = substr($csv, 3); // buang BOM

        return explode("\r\n", rtrim($body, "\r\n"));
    }
}
