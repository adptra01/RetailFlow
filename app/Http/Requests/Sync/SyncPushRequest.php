<?php

namespace App\Http\Requests\Sync;

use App\Enums\SyncOperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi batch push (§11.3 langkah 3, §18).
 *
 * Server memvalidasi bentuk operasi; identitas tenant/perangkat TIDAK
 * dibaca dari sini melainkan dari token perangkat (middleware
 * `AuthenticateDevice`).
 */
class SyncPushRequest extends FormRequest
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
            'operations' => ['required', 'array', 'min:1', 'max:'.$this->maxBatch()],
            'operations.*.operation_id' => ['required', 'uuid', 'distinct'],
            'operations.*.entity_type' => ['required', 'string', 'max:60'],
            'operations.*.entity_id' => ['required', 'string', 'max:64'],
            'operations.*.operation_type' => [
                'required',
                'string',
                Rule::in(array_map(fn (SyncOperationType $t) => $t->value, SyncOperationType::cases())),
            ],
            'operations.*.payload' => ['nullable', 'array'],
            'operations.*.local_sequence' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operations.required' => 'Batch operasi tidak boleh kosong.',
            'operations.max' => 'Batch melebihi batas yang diizinkan.',
            'operations.*.operation_id.distinct' => 'operation_id duplikat di dalam batch.',
        ];
    }

    private function maxBatch(): int
    {
        return (int) config('pos.sync.max_batch', 500);
    }
}
