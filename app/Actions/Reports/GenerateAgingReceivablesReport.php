<?php

namespace App\Actions\Reports;

use App\Models\Receivable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Laporan Umur Piutang (Aging Receivables Report).
 *
 * Mengelompokkan piutang berdasarkan rentang hari jatuh tempo:
 * - Current (belum jatuh tempo)
 * - 1-30 hari
 * - 31-60 hari
 * - 61-90 hari
 * - >90 hari
 */
class GenerateAgingReceivablesReport
{
    /**
     * @param  array{
     *     tenant_id?: string|null,
     *     as_of_date?: string|null,  // default: today
     * }  $options
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(array $options = []): Collection
    {
        $tenantId = $options['tenant_id'] ?? null;
        $asOfDate = $options['as_of_date'] ?? Carbon::now()->format('Y-m-d');
        $asOf = Carbon::parse($asOfDate)->endOfDay();

        $query = Receivable::query()
            ->with(['transaction.customer', 'transaction.tenant'])
            ->where('status', 'open')
            ->where('remaining_minor', '>', 0);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $receivables = $query->orderBy('created_at', 'asc')->get();

        return $receivables->map(function (Receivable $rec) use ($asOf) {
            $dueDate = $rec->due_at ? Carbon::parse($rec->due_at) : null;
            $daysOverdue = $dueDate && $asOf->gt($dueDate)
                ? (int) abs($asOf->diffInDays($dueDate))
                : 0;

            // Determine aging bucket
            $bucket = $this->getAgingBucket($daysOverdue);

            return [
                'id' => $rec->id,
                'customer_name' => $rec->transaction?->customer?->name ?? 'Unknown',
                'invoice_number' => $rec->transaction?->invoice_number ?? 'N/A',
                'original_amount_minor' => $rec->original_amount_minor,
                'original_amount_formatted' => Number::currency($rec->original_amount_minor, 'IDR', 'id'),
                'remaining_minor' => $rec->remaining_minor,
                'remaining_formatted' => Number::currency($rec->remaining_minor, 'IDR', 'id'),
                'created_at' => $rec->created_at?->format('Y-m-d'),
                'due_date' => $rec->due_at?->format('Y-m-d'),
                'days_overdue' => $daysOverdue,
                'aging_bucket' => $bucket,
                'status' => $rec->status,
            ];
        });
    }

    /**
     * Get aging bucket label.
     */
    private function getAgingBucket(int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'Current';
        } elseif ($daysOverdue <= 30) {
            return '1-30 Days';
        } elseif ($daysOverdue <= 60) {
            return '31-60 Days';
        } elseif ($daysOverdue <= 90) {
            return '61-90 Days';
        }

        return '>90 Days';
    }

    /**
     * Generate aging summary.
     *
     * @return array<string, array{count: int, total_minor: int, total_formatted: string}>
     */
    public function getAgingSummary(array $options = []): array
    {
        $data = $this->handle($options);

        $buckets = ['Current', '1-30 Days', '31-60 Days', '61-90 Days', '>90 Days'];

        return array_map(function ($bucket) use ($data) {
            $items = $data->where('aging_bucket', $bucket);
            $total = $items->sum('remaining_minor');

            return [
                'count' => $items->count(),
                'total_minor' => $total,
                'total_formatted' => Number::currency($total, 'IDR', 'id'),
            ];
        }, array_combine($buckets, $buckets));
    }

    /**
     * Get customer-level aging (group by customer).
     *
     * @return array{labels: list<string>, series: list<int>, details: list<array>}
     */
    public function getCustomerAging(array $options = []): array
    {
        $data = $this->handle($options);

        $byCustomer = $data->groupBy('customer_name')->map(function ($items, $customer) {
            $buckets = ['Current' => 0, '1-30 Days' => 0, '31-60 Days' => 0, '61-90 Days' => 0, '>90 Days' => 0];
            $total = 0;

            foreach ($items as $item) {
                $buckets[$item['aging_bucket']] = ($buckets[$item['aging_bucket']] ?? 0) + $item['remaining_minor'];
                $total += $item['remaining_minor'];
            }

            return [
                'customer' => $customer,
                'buckets' => $buckets,
                'total_minor' => $total,
                'total_formatted' => Number::currency($total, 'IDR', 'id'),
            ];
        })->sortByDesc('total_minor')->values();

        return [
            'details' => $byCustomer->toArray(),
            'labels' => $byCustomer->map(fn ($c) => $c['customer'])->toArray(),
            'series' => $byCustomer->map(fn ($c) => $c['total_minor'])->toArray(),
        ];
    }
}
