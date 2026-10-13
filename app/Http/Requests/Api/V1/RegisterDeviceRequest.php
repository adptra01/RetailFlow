<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi `POST /api/v1/devices/register` (§18, §12).
 *
 * `location_id` (bila diisi) harus milik tenant pengguna — tenant TIDAK
 * pernah dibaca dari body permintaan melainkan dari pengguna terautentikasi
 * (§12), dan lokasi tenant lain ditolak sebelum menyentuh use case.
 */
class RegisterDeviceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'platform' => ['required', 'string', 'max:30'],
            'location_id' => [
                'nullable',
                'uuid',
                Rule::exists('locations', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama perangkat wajib diisi.',
            'platform.required' => 'Platform perangkat wajib diisi.',
            'location_id.exists' => 'Lokasi tidak ditemukan pada toko ini.',
        ];
    }
}
