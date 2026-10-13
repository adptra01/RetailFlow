<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi `GET /api/v1/reports/sales` (§18, FR-09).
 *
 * Dua bentuk periode:
 *  - `date=YYYY-MM-DD` — satu hari;
 *  - `from=…&to=…` — rentang inklusif.
 *
 * Tanpa keduanya, laporan hari ini. Urutan `from`/`to` yang terbalik tidak
 * ditolak: domain laporan menormalkannya (lihat `SalesReport::range`).
 */
class SalesReportRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date_format' => 'Tanggal harus berformat YYYY-MM-DD.',
            'from.date_format' => 'Tanggal awal harus berformat YYYY-MM-DD.',
            'to.date_format' => 'Tanggal akhir harus berformat YYYY-MM-DD.',
        ];
    }
}
