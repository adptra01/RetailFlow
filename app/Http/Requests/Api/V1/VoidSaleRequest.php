<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi `POST /api/v1/sales/{id}/void` (§17.4, §18).
 *
 * Alasan pembatalan WAJIB (FR-12/§17.4) dan pemeriksaan izin
 * `transaction.void` dilakukan use case `VoidSale` atas nama pengguna
 * terautentikasi — bukan dari body permintaan.
 */
class VoidSaleRequest extends FormRequest
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
        $tenantId = $this->user()?->tenant_id;

        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'restore_stock' => ['nullable', 'boolean'],
            'device_id' => [
                'nullable',
                'uuid',
                Rule::exists('devices', 'id')->where('tenant_id', $tenantId)->whereNull('revoked_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan minimal 3 karakter.',
            'device_id.exists' => 'Perangkat tidak dikenal atau telah dicabut untuk toko ini.',
        ];
    }
}
