<?php

namespace App\Actions\Reports;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Laporan Pembayaran Supplier (Supplier Payment Report).
 *
 * Menampilkan riwayat pembayaran ke supplier, hutang outstanding,
 * dan ringkasan per supplier.
 */
class GenerateSupplierPaymentReport
{
    /**
     * @param  array{
     *     tenant_id?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     *     supplier_id?: int|null,
     * }  $options
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(array $options = []): Collection
    {
        $tenantId = $options['tenant_id'] ?? null;
        $fromDate = $options['from_date'] ?? null;
        $toDate = $options['to_date'] ?? null;
        $supplierId = $options['supplier_id'] ?? null;

        $query = SupplierPayment::query()
            ->with(['supplier', 'purchaseOrder', 'user'])
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        if ($fromDate) {
            $query->whereDate('payment_date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('payment_date', '<=', $toDate);
        }

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $payments = $query->limit(500)->get();

        return $payments->map(function (SupplierPayment $sp) {
            return [
                'id' => $sp->id,
                'payment_date' => $sp->payment_date?->format('Y-m-d'),
                'payment_date_formatted' => $sp->payment_date?->format('d M Y'),
                'supplier_name' => $sp->supplier?->name ?? 'Unknown',
                'supplier_id' => $sp->supplier_id,
                'purchase_order_number' => $sp->purchaseOrder?->order_number ?? '-',
                'amount_minor' => $sp->amount_minor,
                'amount_formatted' => Number::currency($sp->amount_minor, 'IDR', 'id'),
                'payment_method' => $sp->payment_method,
                'notes' => $sp->notes,
                'user_name' => $sp->user?->name ?? '-',
            ];
        });
    }

    /**
     * Get supplier outstanding balances summary.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getSupplierOutstanding(array $options = []): Collection
    {
        $tenantId = $options['tenant_id'] ?? null;

        $query = Supplier::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('balance_minor', '>', 0)
            ->orderByDesc('balance_minor');

        $suppliers = $query->get();

        return $suppliers->map(function (Supplier $s) {
            // Get recent payment info
            $lastPayment = SupplierPayment::query()
                ->where('supplier_id', $s->id)
                ->orderByDesc('payment_date')
                ->first();

            // Get PO count pending payment
            $poPending = PurchaseOrder::query()
                ->where('supplier_id', $s->id)
                ->whereIn('status', ['pending', 'partial', 'received'])
                ->count();

            $poTotal = PurchaseOrder::query()
                ->where('supplier_id', $s->id)
                ->whereIn('status', ['pending', 'partial', 'received'])
                ->sum('total_minor');

            return [
                'supplier_id' => $s->id,
                'supplier_name' => $s->name,
                'balance_minor' => $s->balance_minor,
                'balance_formatted' => Number::currency($s->balance_minor, 'IDR', 'id'),
                'po_pending_count' => $poPending,
                'po_total_minor' => $poTotal,
                'po_total_formatted' => Number::currency($poTotal, 'IDR', 'id'),
                'last_payment_date' => $lastPayment?->payment_date?->format('Y-m-d'),
                'last_payment_formatted' => $lastPayment?->amount_minor ? Number::currency($lastPayment->amount_minor, 'IDR', 'id') : '-',
            ];
        });
    }

    /**
     * Get summary totals.
     *
     * @return array{total_paid: int, total_outstanding: int, supplier_count: int, po_pending_value: int}
     */
    public function getTotals(array $options = []): array
    {
        $tenantId = $options['tenant_id'] ?? null;
        $fromDate = $options['from_date'] ?? null;
        $toDate = $options['to_date'] ?? null;

        $payments = $this->handle($options);
        $totalPaid = $payments->sum('amount_minor');

        $outstanding = $this->getSupplierOutstanding(['tenant_id' => $tenantId]);
        $totalOutstanding = $outstanding->sum('balance_minor');
        $supplierCount = $outstanding->count();
        $poPendingValue = $outstanding->sum('po_total_minor');

        return [
            'total_paid' => $totalPaid,
            'total_paid_formatted' => Number::currency($totalPaid, 'IDR', 'id'),
            'total_outstanding' => $totalOutstanding,
            'total_outstanding_formatted' => Number::currency($totalOutstanding, 'IDR', 'id'),
            'supplier_count' => $supplierCount,
            'po_pending_value' => $poPendingValue,
            'po_pending_formatted' => Number::currency($poPendingValue, 'IDR', 'id'),
        ];
    }

    /**
     * Get daily payment chart data.
     *
     * @return array{labels: list<string>, series: list<int>}
     */
    public function getDailyChartData(array $options = []): array
    {
        $data = $this->handle($options);

        $daily = $data->groupBy('payment_date')->map(function ($items, $date) {
            return [
                'date' => $date,
                'total' => $items->sum('amount_minor'),
            ];
        })->sortKeys()->values();

        return [
            'labels' => $daily->map(fn ($d) => Carbon::parse($d['date'])->format('d M'))->toArray(),
            'series' => $daily->map(fn ($d) => $d['total'])->toArray(),
        ];
    }
}
