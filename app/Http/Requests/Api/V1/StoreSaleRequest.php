<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi `POST /api/v1/sales` — kontrak checkout §18.
 *
 * Yang dipercaya dari klien hanya: identitas idempotensi (`client_request_id`),
 * apa yang dibeli (`items[].product_id` + `quantity`), diskon, dan bagaimana
 * pembayarannya. **Harga tidak diterima dari klien** — server menghitung ulang
 * dari master (§18), jadi tidak ada medan `unit_price_minor` di sini.
 *
 * `device_id`/`location_id` (bila dikirim) wajib milik tenant pengguna dan
 * perangkatnya belum dicabut; tenant sendiri tidak pernah dibaca dari body
 * (§12) melainkan dari pengguna terautentikasi.
 */
class StoreSaleRequest extends FormRequest
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
            // Idempotensi (§11.1, §18): kunci yang sama = penjualan yang sama.
            'client_request_id' => ['required', 'uuid'],
            'uuid' => ['nullable', 'uuid'],

            'device_id' => [
                'nullable',
                'uuid',
                Rule::exists('devices', 'id')->where('tenant_id', $tenantId)->whereNull('revoked_at'),
            ],
            'location_id' => [
                'nullable',
                'uuid',
                Rule::exists('locations', 'id')->where('tenant_id', $tenantId),
            ],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'min:1'],
            'items.*.discount_minor' => ['nullable', 'integer', 'min:0'],

            'discount' => ['nullable', 'array'],
            'discount.type' => ['required_with:discount', 'string', Rule::in(['fixed', 'percent'])],
            'discount.value' => ['required_with:discount', 'numeric', 'min:0'],

            'payments' => ['nullable', 'array', 'min:1', 'max:5'],
            'payments.*.method' => [
                'required_with:payments',
                'string',
                Rule::in(array_map(
                    static fn (PaymentMethod $method): string => $method->value,
                    PaymentMethod::selectable(),
                )),
            ],
            'payments.*.amount_minor' => ['required_with:payments', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.notes' => ['nullable', 'string', 'max:255'],

            // Jalur tanpa daftar tender (mis. penjualan kredit tanpa uang muka).
            'paid_minor' => ['nullable', 'integer', 'min:0', 'required_without:payments'],
            'payment_method' => [
                'nullable',
                'string',
                'required_with:paid_minor',
                Rule::in(array_map(
                    static fn (PaymentMethod $method): string => $method->value,
                    PaymentMethod::selectable(),
                )),
            ],

            'customer_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'due_at' => ['nullable', 'date'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
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
            'items.required' => 'Penjualan harus memuat minimal satu barang.',
            'items.*.quantity.min' => 'Kuantitas minimal 1.',
            'payments.*.method.in' => 'Metode pembayaran tidak dikenal.',
            'payments.*.amount_minor.min' => 'Nominal pembayaran harus lebih dari nol.',
            'device_id.exists' => 'Perangkat tidak dikenal atau telah dicabut untuk toko ini.',
            'location_id.exists' => 'Lokasi tidak ditemukan pada toko ini.',
            'customer_id.exists' => 'Pelanggan tidak ditemukan pada toko ini.',
        ];
    }
}
