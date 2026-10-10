<?php

namespace App\Support\Imports;

/**
 * Parser CSV produk untuk impor (FR-02: "Impor harus menyediakan pratinjau,
 * laporan kesalahan, dan mekanisme koreksi sebelum menyimpan.").
 *
 * - Header memakai alias Indonesia/Inggris (mis. `harga_jual` == `price`).
 * - Baris kosong dan komentar (diawali `#`) dilewati — template boleh
 *   menyertakan contoh baris berkomentar.
 * - Delimiter dideteksi otomatis: titik-koma `;`, koma `,`, atau tab.
 * - Baris yang jumlah kolomnya tidak sama dengan header dilaporkan sebagai
 *   kesalahan tingkat berkas dan dikeluarkan (mekanisme koreksi: perbaiki
 *   barisnya lalu impor ulang).
 * - Nilai sel dipertahankan mentah (string); validasi dilakukan oleh use
 *   case `ImportProducts` agar laporan kesalahannya terstruktur per baris.
 */
final class ProductCsvParser
{
    /** Kolom kanonik => alias (sudah ternormalisasi: lowercase, spasi -> _). */
    private const ALIASES = [
        'name' => ['name', 'nama', 'product', 'produk', 'nama_produk'],
        'sku' => ['sku', 'kode', 'kode_sku', 'kode_produk', 'item_code'],
        'barcode' => ['barcode', 'ean', 'upc', 'kode_barcode'],
        'category' => ['category', 'kategori', 'kategori_produk'],
        'price' => ['price', 'harga', 'harga_jual', 'selling_price'],
        'buy_price' => ['buy_price', 'harga_beli', 'harga_modal', 'cost', 'modal'],
        'stock' => ['stock', 'stok', 'qty', 'quantity'],
        'min_stock' => ['min_stock', 'stok_minimum', 'min'],
        'is_active' => ['is_active', 'aktif', 'active'],
    ];

    /**
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, errors: list<string>}
     */
    public function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);

        $errors = [];
        $headerCells = [];

        foreach ($lines as $index => $line) {
            if (! $this->isMeaningfulLine($line)) {
                continue;
            }

            $delimiter = $this->detectDelimiter($line);

            if ($delimiter === null) {
                return [
                    'headers' => [],
                    'rows' => [],
                    'errors' => ['Tidak dapat mendeteksi pemisah kolom; gunakan koma, titik-koma, atau tab.'],
                ];
            }

            $headerCells = str_getcsv($line, $delimiter);
            $headers = $this->mapHeaders($headerCells, $errors);

            break;
        }

        if ($headerCells === []) {
            return ['headers' => [], 'rows' => [], 'errors' => ['Berkas kosong atau tidak berisi data.']];
        }

        if (! in_array('name', $headers, true) || ! in_array('price', $headers, true)) {
            return [
                'headers' => $headers,
                'rows' => [],
                'errors' => [...$errors, 'Kolom wajib tidak ditemukan di baris header: `name` dan `price`.'],
            ];
        }

        $rows = [];
        $rowCount = 0;

        foreach ($lines as $index => $line) {
            if (! $this->isMeaningfulLine($line)) {
                continue;
            }

            $rowCount++;

            if ($rowCount === 1) {
                continue; // baris header
            }

            $cells = str_getcsv($line, $delimiter);

            if (count($cells) !== count($headers)) {
                $errors[] = 'Baris '.($index + 1).': jumlah kolom ('.count($cells)
                    .') tidak sama dengan header ('.count($headers).').';

                continue;
            }

            $row = array_combine($headers, $cells);
            $row['line'] = $index + 1;
            $rows[] = $row;
        }

        if ($rows === []) {
            $errors[] = 'Tidak ada baris data yang dapat diimpor.';
        }

        return ['headers' => $headers, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * Template CSV yang bisa diunduh pengguna (baris contoh dikomentari).
     */
    public static function template(): string
    {
        return implode("\n", [
            'name,sku,barcode,category,price,buy_price,stock,min_stock,is_active',
            '# Contoh baris (hapus tanda # untuk memakai):',
            '# Kopi Arabica 250g,KOPI-AR-250,8991001001001,Minuman,45000,38000,50,10,1',
        ])."\n";
    }

    /**
     * @param  list<string>  $cells
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function mapHeaders(array $cells, array &$errors): array
    {
        $mapped = [];
        $seen = [];

        foreach ($cells as $cell) {
            $key = $this->headerKey($cell);

            if (isset($seen[$key])) {
                $errors[] = "Header duplikat: '{$cell}' (diabaikan).";

                continue;
            }

            $seen[$key] = true;

            $canonical = $this->canonicalKey($key);

            if ($canonical === null) {
                $errors[] = "Kolom tidak dikenal diabaikan: '{$cell}'.";
                $mapped[] = $key;

                continue;
            }

            $mapped[] = $canonical;
        }

        return $mapped;
    }

    private function headerKey(string $cell): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', '_', trim($cell)), '_'));
    }

    private function canonicalKey(string $key): ?string
    {
        foreach (self::ALIASES as $canonical => $aliases) {
            if (in_array($key, $aliases, true)) {
                return $canonical;
            }
        }

        return null;
    }

    private function isMeaningfulLine(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed !== '' && ! str_starts_with($trimmed, '#');
    }

    private function detectDelimiter(string $line): ?string
    {
        $best = null;
        $bestCount = 0;

        foreach ([';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")] as $delimiter => $count) {
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }
}