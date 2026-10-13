<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi query `GET /api/v1/products` (§18).
 *
 * Katalog bisa besar, jadi daftar produk dijaga tetap ramping: `search`
 * (nama/SKU/barcode), filter kategori, dan — yang terpenting untuk klien
 * offline — `updated_since` agar perangkat dapat menyegarkan katalog secara
 * inkremental alih-alih mengunduh semuanya berulang kali.
 */
class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'updated_since' => ['nullable', 'date'],
            'include_inactive' => ['nullable', 'boolean'],
            'include_units' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', Rule::in(['name', 'sku', 'updated_at', 'stock'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sort.in' => 'Urutan tidak dikenal.',
            'direction.in' => 'Arah urutan tidak dikenal.',
            'per_page.max' => 'Jumlah per halaman maksimal 200.',
        ];
    }
}
