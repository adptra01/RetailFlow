<?php

namespace App\Actions\Reports;

use App\Models\Receivable;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * FR-07: Laporan Piutang (Credit Sales).
 *
 * Laporan ini mengumpulkan semua piutang yang statusnya masih 'open'
 * dalam rentang tanggal yang ditentukan.
 */
class GenerateReceivableReport
{
    /**
     * @param  array{tenant_id?: int|null, from_date?: string|null, to_date?: string|null}  $options
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(array $options = []): Collection
    {
        $query = Receivable::query()
            ->with(['transaction.customer', 'transaction.tenant'])
            ->where('status', 'open');

        if (isset($options['tenant_id'])) {
            $query->where('tenant_id', $options['tenant_id']);
        }

        if (isset($options['from_date']) && ! empty($options['from_date'])) {
            $query->whereDate('created_at', '>=', $options['from_date']);
        }

        if (isset($options['to_date']) && ! empty($options['to_date'])) {
            $query->whereDate('created_at', '<=', $options['to_date']);
        }

        // Urutkan berdasarkan tanggal pembuatan piutang atau tanggal jatuh tempo
        $receivables = $query->orderBy('created_at', 'desc')->get();

        return $receivables->map(function (Receivable $receivable) {
            return [
                'id' => $receivable->id,
                'tenant_name' => $receivable->transaction?->tenant?->name ?? __('Unknown Tenant'),
                'customer_name' => $receivable->transaction?->customer?->name ?? __('Umum'),
                'invoice_number' => $receivable->transaction?->invoice_number ?? __('N/A'),
                'amount_minor' => $receivable->original_amount_minor,
                'amount_formatted' => Number::currency((float) $receivable->original_amount_minor, 'IDR', 'id'),
                'created_at' => $receivable->created_at?->format('Y-m-d'),
                'due_date' => $receivable->due_at?->format('Y-m-d'),
                'status' => $receivable->status,
            ];
        });
    }
}
