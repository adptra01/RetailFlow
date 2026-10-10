<?php

namespace App\Support\Exports;

/**
 * Ekspor CSV sederhana (FR-09: "Ekspor CSV").
 *
 * - Ditulis dengan UTF-8 BOM agar Excel mengenali encoding dengan benar.
 * - Delimiter default titik-koma (konvensi lembar kerja Indonesia);
 *   sel yang mengandung delimiter, tanda kutip, atau baris baru di-quote,
 *   dan tanda kutip di dalam nilai didobel.
 * - Baris per record: header menentukan urutan & subset kolom; kunci yang
 *   tidak ada dianggap kosong.
 */
final class CsvExporter
{
    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    public static function fromRows(array $headers, array $rows, string $delimiter = ';'): string
    {
        $out = "\xEF\xBB\xBF".self::line($headers, $delimiter);

        foreach ($rows as $row) {
            $cells = [];

            foreach ($headers as $header) {
                $cells[] = $row[$header] ?? null;
            }

            $out .= self::line($cells, $delimiter);
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $cells
     */
    private static function line(array $cells, string $delimiter): string
    {
        $parts = [];

        foreach ($cells as $cell) {
            $value = $cell === null ? '' : (string) $cell;

            if (str_contains($value, $delimiter)
                || str_contains($value, '"')
                || str_contains($value, "\n")
                || str_contains($value, "\r")) {
                $value = '"'.str_replace('"', '""', $value).'"';
            }

            $parts[] = $value;
        }

        return implode($delimiter, $parts)."\r\n";
    }
}
