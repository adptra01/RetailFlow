<?php

namespace App\Support\Imports;

/**
 * Laporan hasil impor produk (FR-02: pratinjau + laporan kesalahan).
 *
 * Setiap baris sumber dicatat dengan status:
 *  - `failed` : tidak valid — `errors` berisi alasan per kolom (koreksi lalu impor ulang);
 *  - `valid`  : lolos validasi (mode pratinjau, belum disimpan);
 *  - `created`: berhasil disimpan (mode commit) — `product_id` tersedia.
 */
final class ProductImportResult
{
    /**
     * @var list<array{
     *     line: int,
     *     status: string,
     *     errors: list<string>,
     *     data: array<string, mixed>,
     *     product_id?: int,
     * }>
     */
    public array $rows = [];

    public int $total = 0;

    public int $valid = 0;

    public int $failed = 0;

    public int $created = 0;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $errors
     */
    public function record(int $line, string $status, array $data, array $errors = [], ?int $productId = null): void
    {
        $this->rows[] = [
            'line' => $line,
            'status' => $status,
            'errors' => $errors,
            'data' => $data,
            ...($productId !== null ? ['product_id' => $productId] : []),
        ];

        $this->total++;

        match ($status) {
            'created' => $this->created++,
            'valid' => $this->valid++,
            'failed' => $this->failed++,
            default => null,
        };
    }
}
