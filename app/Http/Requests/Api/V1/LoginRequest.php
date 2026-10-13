<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi `POST /api/v1/auth/login` (§18).
 *
 * `device_name` + `platform` wajib: token mewakili manusia, tetapi selalu
 * terikat pada satu perangkat tertentu (nama perangkat dipakai sebagai nama
 * token) sehingga sesi per perangkat dapat dikenali dan login ulang di
 * perangkat yang sama mencabut token lamanya.
 */
class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['required', 'string', 'max:30'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak sah.',
            'password.required' => 'Kata sandi wajib diisi.',
            'device_name.required' => 'Nama perangkat wajib diisi.',
            'platform.required' => 'Platform perangkat wajib diisi.',
        ];
    }
}
