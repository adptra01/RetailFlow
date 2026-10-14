<?php

namespace App\Actions\Reports;

use App\Models\TransactionItem;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan Margin Historis (Margin History Report).
 *
 * Menghitung margin kotor per periode (harian/mingguan/bulanan) berdasarkan:
 * - Revenue: sum(subtotal_minor) dari TransactionItem (§17.1), dikonversi major
 *   untuk tampilan
 * - COGS: sum(buy_price * quantity) dari TransactionItem via Product
 * - Margin: Revenue - COGS
 * - Margin %: (Margin / Revenue) * 100
 *
 * Query SQL selalu mengelompokkan per hari (DATE()) agar portabel antara
 * SQLite dan MySQL; pengelompokan minggu/bulan dilakukan di PHP.
 */
class GenerateMarginHistoryReport
{
    /**
     * @param  array{
     *     tenant_id?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     *     group_by?: 'day'|'week'|'month',
     * }  $options
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(array $options = []): Collection
    {
        $tenantId = $options['tenant_id'] ?? null;
        $fromDate = $options['from_date'] ?? null;
        $toDate = $options['to_date'] ?? null;
        $groupBy = $options['group_by'] ?? 'day';

        $query = TransactionItem::query()
            ->selectRaw('
                DATE(transactions.created_at) as sale_date,
                SUM(transaction_items.subtotal_minor) as revenue,
                SUM(products.buy_price * transaction_items.quantity) as cogs
            ')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->whereNotIn('transactions.payment_status', ['void', 'cancelled']);

        if ($tenantId) {
            $query->where('transactions.tenant_id', $tenantId);
        }

        if ($fromDate) {
            $query->whereDate('transactions.created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('transactions.created_at', '<=', $toDate);
        }

        $daily = $query
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->limit(370)
            ->get()
            ->map(fn ($row) => [
                'sale_date' => $row->sale_date,
                'revenue' => (new Money((int) $row->revenue))->major(),
                'cogs' => (float) $row->cogs,
            ]);

        return $this->groupByPeriod($daily, $groupBy);
    }

    /**
     * Aggregate daily rows into day/week/month buckets.
     *
     * @param  Collection<int, array{sale_date: string, revenue: float, cogs: float}>  $daily
     * @return Collection<int, array<string, mixed>>
     */
    private function groupByPeriod(Collection $daily, string $groupBy): Collection
    {
        $keyOf = function (array $row) use ($groupBy): string {
            $date = Carbon::parse($row['sale_date']);

            return match ($groupBy) {
                'week' => $date->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
                'month' => $date->format('Y-m'),
                default => $date->format('Y-m-d'),
            };
        };

        $periods = $daily->groupBy($keyOf)->map(function ($rows, $key) use ($groupBy) {
            $revenue = $rows->sum('revenue');
            $cogs = $rows->sum('cogs');
            $margin = $revenue - $cogs;
            $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 2) : 0;

            return [
                'period' => $key,
                'label' => $this->labelFor($key, $groupBy),
                'revenue' => $revenue,
                'cogs' => $cogs,
                'margin' => $margin,
                'margin_pct' => $marginPct,
                'transactions' => $rows->count(),
            ];
        })->sortKeys()->values();

        return $periods;
    }

    private function labelFor(string $period, string $groupBy): string
    {
        return match ($groupBy) {
            'week' => 'W'.Carbon::parse($period)->format('W Y'),
            'month' => Carbon::parse($period.'-01')->format('M Y'),
            default => Carbon::parse($period)->format('d M'),
        };
    }

    /**
     * Generate margin by product.
     *
     * @param  array{
     *     tenant_id?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     *     limit?: int,
     * }  $options
     * @return Collection<int, array<string, mixed>>
     */
    public function getProductMargins(array $options = []): Collection
    {
        $tenantId = $options['tenant_id'] ?? null;
        $fromDate = $options['from_date'] ?? null;
        $toDate = $options['to_date'] ?? null;
        $limit = $options['limit'] ?? 20;

        $query = TransactionItem::query()
            ->selectRaw('
                products.id as product_id,
                products.name as product_name,
                products.sku as product_sku,
                SUM(transaction_items.quantity) as qty_sold,
                SUM(transaction_items.subtotal_minor) as revenue,
                SUM(products.buy_price * transaction_items.quantity) as cogs
            ')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->whereNotIn('transactions.payment_status', ['void', 'cancelled'])
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('revenue')
            ->limit($limit);

        if ($tenantId) {
            $query->where('transactions.tenant_id', $tenantId);
        }

        if ($fromDate) {
            $query->whereDate('transactions.created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('transactions.created_at', '<=', $toDate);
        }

        $raw = $query->get();

        return $raw->map(function ($row) {
            $revenue = (new Money((int) $row->revenue))->major();
            $cogs = (float) $row->cogs;
            $margin = $revenue - $cogs;
            $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 2) : 0;

            return [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'product_sku' => $row->product_sku,
                'qty_sold' => (int) $row->qty_sold,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'margin' => $margin,
                'margin_pct' => $marginPct,
            ];
        });
    }

    /**
     * Get overall totals.
     *
     * @return array{total_revenue: float, total_cogs: float, total_margin: float, avg_margin_pct: float}
     */
    public function getTotals(array $options = []): array
    {
        $data = $this->handle($options);

        $totalRevenue = $data->sum('revenue');
        $totalCogs = $data->sum('cogs');
        $totalMargin = $data->sum('margin');
        $avgMarginPct = $totalRevenue > 0 ? round(($totalMargin / $totalRevenue) * 100, 2) : 0;

        return [
            'total_revenue' => $totalRevenue,
            'total_cogs' => $totalCogs,
            'total_margin' => $totalMargin,
            'avg_margin_pct' => $avgMarginPct,
        ];
    }

    /**
     * Get chart data for margin over time.
     *
     * @return array{labels: list<string>, series_revenue: list<float>, series_cogs: list<float>, series_margin: list<float>, series_margin_pct: list<float>}
     */
    public function getChartData(array $options = []): array
    {
        $data = $this->handle($options);

        return [
            'labels' => $data->map(fn ($d) => $d['label'])->toArray(),
            'series_revenue' => $data->map(fn ($d) => $d['revenue'])->toArray(),
            'series_cogs' => $data->map(fn ($d) => $d['cogs'])->toArray(),
            'series_margin' => $data->map(fn ($d) => $d['margin'])->toArray(),
            'series_margin_pct' => $data->map(fn ($d) => $d['margin_pct'])->toArray(),
        ];
    }
}
