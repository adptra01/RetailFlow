<?php

namespace App\Actions\Reports;

use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan Pergerakan Stok (Stock Movement Report).
 *
 * Menampilkan mutasi stok masuk/keluar per produk dalam periode tertentu.
 * Termasuk breakdown per tipe referensi (sale, purchase, adjustment, opname, return, goods_receipt, purchase_return).
 */
class GenerateStockMovementReport
{
    /**
     * @param  array{
     *     tenant_id?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     *     product_id?: int|null,
     *     reference_type?: string|null,
     * }  $options
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(array $options = []): Collection
    {
        $query = StockMovement::query()
            ->with(['product', 'product.category', 'product.units', 'user'])
            ->orderBy('created_at', 'desc');

        if (isset($options['tenant_id'])) {
            $query->where('tenant_id', $options['tenant_id']);
        }

        if (isset($options['from_date']) && ! empty($options['from_date'])) {
            $query->whereDate('created_at', '>=', $options['from_date']);
        }

        if (isset($options['to_date']) && ! empty($options['to_date'])) {
            $query->whereDate('created_at', '<=', $options['to_date']);
        }

        if (isset($options['product_id'])) {
            $query->where('product_id', $options['product_id']);
        }

        if (isset($options['reference_type']) && ! empty($options['reference_type'])) {
            $query->where('reference_type', $options['reference_type']);
        }

        $movements = $query->limit(500)->get();

        return $movements->map(function (StockMovement $movement) {
            $baseUnit = $movement->product?->units?->firstWhere('is_base', true);

            return [
                'id' => $movement->id,
                'date' => $movement->created_at?->format('Y-m-d H:i'),
                'product_name' => $movement->product?->name ?? 'Unknown',
                'product_sku' => $movement->product?->sku ?? '-',
                'category' => $movement->product?->category?->name ?? '-',
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'quantity_change' => $movement->quantity_change,
                'before_stock' => $movement->before_stock,
                'after_stock' => $movement->after_stock,
                'unit' => $baseUnit?->abbreviation ?? 'PCS',
                'user_name' => $movement->user?->name ?? 'System',
                'notes' => $movement->notes,
                'type' => $movement->quantity_change > 0 ? 'in' : 'out',
            ];
        });
    }

    /**
     * Generate summary by reference type.
     *
     * @param  array{
     *     tenant_id?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     * }  $options
     * @return array<string, array{total_in: int, total_out: int, count: int}>
     */
    public function getSummaryByType(array $options = []): array
    {
        $query = StockMovement::query();

        if (isset($options['tenant_id'])) {
            $query->where('tenant_id', $options['tenant_id']);
        }

        if (isset($options['from_date']) && ! empty($options['from_date'])) {
            $query->whereDate('created_at', '>=', $options['from_date']);
        }

        if (isset($options['to_date']) && ! empty($options['to_date'])) {
            $query->whereDate('created_at', '<=', $options['to_date']);
        }

        $summary = $query
            ->selectRaw('reference_type, 
                SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) as total_in,
                SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as total_out,
                COUNT(*) as count')
            ->groupBy('reference_type')
            ->get()
            ->keyBy('reference_type');

        return $summary->map(fn ($s) => [
            'total_in' => (int) $s->total_in,
            'total_out' => (int) $s->total_out,
            'count' => (int) $s->count,
        ])->toArray();
    }

    /**
     * Generate daily stock movement for chart.
     *
     * @param  array{
     *     tenant_id?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     * }  $options
     * @return array{labels: list<string>, series_in: list<int>, series_out: list<int>}
     */
    public function getDailyChartData(array $options = []): array
    {
        $query = StockMovement::query();

        if (isset($options['tenant_id'])) {
            $query->where('tenant_id', $options['tenant_id']);
        }

        if (isset($options['from_date']) && ! empty($options['from_date'])) {
            $query->whereDate('created_at', '>=', $options['from_date']);
        }

        if (isset($options['to_date']) && ! empty($options['to_date'])) {
            $query->whereDate('created_at', '<=', $options['to_date']);
        }

        $daily = $query
            ->selectRaw('DATE(created_at) as date,
                SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) as total_in,
                SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as total_out')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'labels' => $daily->map(fn ($d) => Carbon::parse($d->date)->format('d M'))->toArray(),
            'series_in' => $daily->map(fn ($d) => (int) $d->total_in)->toArray(),
            'series_out' => $daily->map(fn ($d) => (int) $d->total_out)->toArray(),
        ];
    }
}
