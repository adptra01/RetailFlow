<?php

namespace App\Actions\Products;

use App\Models\Category;
use App\Models\Product;
use App\Support\Imports\ProductImportResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Use case "Impor produk" (plan §10.2 `ImportProducts`, FR-02).
 *
 * Dua mode:
 *  - `preview()`: validasi semua baris dan hasilkan laporan kesalahan per
 *    baris TANPA menyimpan — dasar pratinjau + mekanisme koreksi FR-02;
 *  - `commit()`: baris valid disimpan dalam satu transaksi atomik; baris
 *    gagal dilaporkan untuk dikoreksi lalu diimpor ulang.
 *
 * Kebijakan unik toko (FR-02): nama, SKU, dan harga jual (> 0) wajib diisi;
 * SKU/barcode unik di dalam berkas DAN terhadap database. Constraint
 * `sku`/`barcode`/`slug`/`kategori.name` bersifat global (warisan), jadi
 * pengecekan dilakukan tanpa auto-scope tenant agar sesuai kenyataan schema.
 *
 * Kategori dibuat otomatis bila belum ada (find-or-create per tenant);
 * `slug` dihasilkan dari nama dengan akhiran unik. Kolom `category_id`
 * bersifat NOT NULL pada skema, jadi baris tanpa kategori diberi kategori
 * default `Tanpa Kategori`. Stok yang diisi pada impor dicatat langsung ke
 * kolom `stock` (stok awal; integrasi dengan buku besar stok untuk pembukaan
 * stok menyusul — konsisten dengan CRUD produk yang sudah ada). Satuan dasar
 * produk bukan bagian impor pada irisan ini.
 */
class ImportProducts
{
    public function __construct(private readonly ?string $tenantId = null) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function preview(array $rows): ProductImportResult
    {
        return $this->process($rows, commit: false);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function commit(array $rows): ProductImportResult
    {
        return DB::transaction(fn () => $this->process($rows, commit: true));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function process(array $rows, bool $commit): ProductImportResult
    {
        $result = new ProductImportResult;
        $tenantId = $this->tenantId ?? app(TenantContext::class)->id();

        $seenSku = [];
        $seenBarcode = [];

        foreach ($rows as $row) {
            $line = (int) ($row['line'] ?? 0);
            $errors = $this->validate($row, $seenSku, $seenBarcode);

            if ($errors !== []) {
                $result->record($line, 'failed', $row, errors: $errors);

                continue;
            }

            if (! $commit) {
                $result->record($line, 'valid', $row);

                continue;
            }

            $product = $this->createProduct($row, $tenantId);

            if ($product->sku) {
                $seenSku[strtolower($product->sku)] = true;
            }

            if ($product->barcode) {
                $seenBarcode[strtolower($product->barcode)] = true;
            }

            $result->record($line, 'created', $row, productId: $product->id);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $seenSku
     * @param  array<string, true>  $seenBarcode
     * @return list<string>
     */
    private function validate(array $row, array &$seenSku, array &$seenBarcode): array
    {
        $errors = [];

        $name = $this->clean($row['name'] ?? null);
        $sku = $this->clean($row['sku'] ?? null);
        $barcode = $this->clean($row['barcode'] ?? null);
        $price = $this->clean($row['price'] ?? null);
        $buyPrice = $this->clean($row['buy_price'] ?? null);
        $stock = $this->clean($row['stock'] ?? null);
        $minStock = $this->clean($row['min_stock'] ?? null);

        if ($name === null) {
            $errors[] = 'Nama produk wajib diisi.';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'Nama produk maksimal 200 karakter.';
        }

        if ($price === null) {
            $errors[] = 'Harga jual wajib diisi.';
        } elseif (! is_numeric($price) || (float) $price <= 0) {
            $errors[] = 'Harga jual harus angka lebih dari 0.';
        }

        if ($buyPrice !== null && (! is_numeric($buyPrice) || (float) $buyPrice < 0)) {
            $errors[] = 'Harga beli harus angka 0 atau lebih.';
        }

        if ($stock !== null && ! preg_match('/^\d+$/', $stock)) {
            $errors[] = 'Stok harus bilangan bulat 0 atau lebih.';
        }

        if ($minStock !== null && ! preg_match('/^\d+$/', $minStock)) {
            $errors[] = 'Stok minimum harus bilangan bulat 0 atau lebih.';
        }

        if ($sku !== null) {
            if (mb_strlen($sku) > 50) {
                $errors[] = 'SKU maksimal 50 karakter.';
            }

            $key = strtolower($sku);

            if (isset($seenSku[$key])) {
                $errors[] = "SKU '{$sku}' duplikat di dalam berkas.";
            } elseif (Product::query()->withoutGlobalScope('tenant')->withTrashed()->where('sku', $sku)->exists()) {
                $errors[] = "SKU '{$sku}' sudah digunakan.";
            }
        } else {
            $errors[] = 'SKU wajib diisi.';
        }

        if ($barcode !== null) {
            if (mb_strlen($barcode) > 100) {
                $errors[] = 'Barcode maksimal 100 karakter.';
            }

            $key = strtolower($barcode);

            if (isset($seenBarcode[$key])) {
                $errors[] = "Barcode '{$barcode}' duplikat di dalam berkas.";
            } elseif (Product::query()->withoutGlobalScope('tenant')->withTrashed()->where('barcode', $barcode)->exists()) {
                $errors[] = "Barcode '{$barcode}' sudah digunakan.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createProduct(array $row, string $tenantId): Product
    {
        $name = trim((string) $row['name']);
        $category = $this->clean($row['category'] ?? null) ?? 'Tanpa Kategori';

        return Product::create([
            'tenant_id' => $tenantId,
            'category_id' => $this->resolveCategoryId($category, $tenantId),
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'sku' => $this->clean($row['sku'] ?? null),
            'barcode' => $this->clean($row['barcode'] ?? null),
            'price' => round((float) $row['price'], 2),
            // `buy_price` NOT NULL dengan default 0.00 pada skema warisan —
            // kosong berarti "belum diketahui" (0), bukan null.
            'buy_price' => round((float) ($this->clean($row['buy_price'] ?? null) ?? 0), 2),
            'stock' => (int) ($this->clean($row['stock'] ?? null) ?? 0),
            'min_stock' => (int) ($this->clean($row['min_stock'] ?? null) ?? 0),
            'is_active' => $this->parseBool($row['is_active'] ?? null, true),
            'is_unlimited_stock' => false,
        ]);
    }

    private function resolveCategoryId(string $name, string $tenantId): ?int
    {
        $existing = Category::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            return $existing->id;
        }

        try {
            return Category::create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'slug' => $this->uniqueSlug($name, Category::class),
            ])->id;
        } catch (QueryException $e) {
            // Constraint `categories.name` unik GLOBAL (warisan): kategori tenant
            // lain bisa menabrak nama yang sama. Fallback: pakai kategori yang
            // sudah ada (transisi; unik-per-tenant menyusul di migration depan).
            if (! in_array((int) ($e->errorInfo[1] ?? 0), [19, 1062], true)) {
                throw $e;
            }

            $fallback = Category::query()
                ->withoutGlobalScope('tenant')
                ->where('name', $name)
                ->first();

            return $fallback?->id;
        }
    }

    /**
     * @param  class-string  $model
     */
    private function uniqueSlug(string $name, string $model = Product::class): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;

        for ($i = 2; $model::query()->withoutGlobalScope('tenant')->withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function parseBool(mixed $value, bool $default): bool
    {
        $value = $this->clean($value);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'y', 'ya', 'aktif'], true);
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}