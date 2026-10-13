<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi `POST /api/v1/receivables/{id}/payments` (§18, FR-07).
 *
 * Idempotensi wajib (`client_request_id`) karena ini endpoint TULIS yang
 * menggerakkan uang: pengiriman ulang dari perangkat dengan koneksi buruk
 * tidak boleh menagih pelanggan dua kali. Tenant tidak pernah dibaca dari body
 * (§12); piutangnya ditemukan lewat rujukan pada URL dan dibatasi tenant.
 */
class StoreReceivablePaymentRequest extends FormRequest
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
            'client_request_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'payment_method' => [
                'required',
                'string',
                Rule::in(array_map(
                    static fn (PaymentMethod $method): string => $method->value,
                    PaymentMethod::selectable(),
                )),
            ],
            'notes' => ['nullable', 'string', 'max:255'],
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
            'client_request_id.required' => 'client_request_id wajib dikirim untuk menjamin idempotensi.',
            'client_request_id.uuid' => 'client_request_id harus berupa UUID.',
            'amount_minor.required' => 'Nominal pembayaran wajib diisi.',
            'amount_minor.min' => 'Nominal pembayaran harus lebih dari nol.',
            'payment_method.required' => 'Metode pembayaran wajib diisi.',
            'payment_method.in' => 'Metode pembayaran tidak dikenal.',
            'device_id.exists' => 'Perangkat tidak dikenal atau telah dicabut untuk toko ini.',
        ];
    }
}
