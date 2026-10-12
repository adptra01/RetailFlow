<?php

namespace App\Actions\Reports;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\ReceivablePayment;
use App\Models\SupplierPayment;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan Arus Kas (Cash Flow Report).
 *
 * Menampilkan pemasukan (penjualan, pembayaran piutang) dan pengeluaran
 * (pembelian, retur pembelian, pembayaran supplier) per hari/bulan.
 */
class GenerateCashFlowReport
{
    /**
     * @param  array{
     *     tenant_id?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     * }  $options
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(array $options = []): Collection
    {
        $tenantId = $options['tenant_id'] ?? null;
        $fromDate = $options['from_date'] ?? null;
        $toDate = $options['to_date'] ?? null;

        $inflows = $this->getInflows($tenantId, $fromDate, $toDate);
        $outflows = $this->getOutflows($tenantId, $fromDate, $toDate);

        // Combine and sort by date
        $all = $inflows->merge($outflows)
            ->sortByDesc('date')
            ->values();

        return $all;
    }

    /**
     * Get cash inflows (money coming in).
     */
    private function getInflows(?string $tenantId, ?string $fromDate, ?string $toDate): Collection
    {
        $inflows = collect();

        // 1. Cash Sales (paid in full at transaction time)
        $cashSales = Transaction::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate))
            ->where('payment_status', 'paid')
            // 'mixed' = pembayaran campuran (FR-05); nominal `paid_minor` tetap
            // seluruh uang yang diterima, jadi ia tetap arus kas masuk.
            ->whereIn('payment_method', ['cash', 'transfer', 'debit_card', 'credit_card', 'qris_static', 'mixed'])
            ->get()
            ->map(function ($tx) {
                return [
                    'date' => $tx->created_at?->format('Y-m-d'),
                    'date_formatted' => $tx->created_at?->format('d M Y'),
                    'type' => 'in',
                    'category' => 'penjualan_tunai',
                    'description' => 'Penjualan Tunai #'.$tx->invoice_number,
                    'amount_minor' => $tx->paid_minor,
                    'reference' => $tx->uuid,
                    'payment_method' => $tx->payment_method,
                ];
            });

        $inflows = $inflows->merge($cashSales);

        // 2. Receivable Payments (pelunasan piutang)
        $receivablePayments = ReceivablePayment::query()
            ->with('receivable.transaction')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate))
            ->get()
            ->map(function ($payment) {
                return [
                    'date' => $payment->created_at?->format('Y-m-d'),
                    'date_formatted' => $payment->created_at?->format('d M Y'),
                    'type' => 'in',
                    'category' => 'pelunasan_piutang',
                    'description' => 'Pelunasan Piutang #'.($payment->receivable?->transaction?->invoice_number ?? 'N/A'),
                    'amount_minor' => $payment->amount_minor,
                    'reference' => $payment->uuid,
                    'payment_method' => $payment->payment_method,
                ];
            });

        $inflows = $inflows->merge($receivablePayments);

        // 3. Purchase Returns (uang kembali dari supplier)
        $purchaseReturns = PurchaseReturn::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate))
            ->get()
            ->map(function ($pr) {
                return [
                    'date' => $pr->created_at?->format('Y-m-d'),
                    'date_formatted' => $pr->created_at?->format('d M Y'),
                    'type' => 'in',
                    'category' => 'retur_pembelian',
                    'description' => 'Retur Pembelian #'.$pr->return_number,
                    'amount_minor' => $pr->total_minor,
                    'reference' => $pr->uuid,
                    'payment_method' => 'refund',
                ];
            });

        $inflows = $inflows->merge($purchaseReturns);

        return $inflows;
    }

    /**
     * Get cash outflows (money going out).
     */
    private function getOutflows(?string $tenantId, ?string $fromDate, ?string $toDate): Collection
    {
        $outflows = collect();

        // 1. Purchase Orders (pembelian - when goods received or paid)
        $purchaseOrders = PurchaseOrder::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate))
            ->whereIn('status', ['received', 'partial'])
            ->get()
            ->map(function ($po) {
                // Use total_minor as estimated outflow (actual payment tracked separately)
                return [
                    'date' => $po->created_at?->format('Y-m-d'),
                    'date_formatted' => $po->created_at?->format('d M Y'),
                    'type' => 'out',
                    'category' => 'pembelian',
                    'description' => 'Pembelian #'.$po->order_number,
                    'amount_minor' => $po->total_minor,
                    'reference' => $po->uuid,
                    'payment_method' => 'pending',
                ];
            });

        $outflows = $outflows->merge($purchaseOrders);

        // 2. Supplier Payments
        $supplierPayments = SupplierPayment::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate))
            ->get()
            ->map(function ($sp) {
                return [
                    'date' => $sp->created_at?->format('Y-m-d'),
                    'date_formatted' => $sp->created_at?->format('d M Y'),
                    'type' => 'out',
                    'category' => 'pembayaran_supplier',
                    'description' => 'Bayar Supplier #'.($sp->purchaseOrder?->order_number ?? $sp->supplier?->name),
                    'amount_minor' => $sp->amount_minor,
                    'reference' => $sp->uuid,
                    'payment_method' => $sp->payment_method,
                ];
            });

        $outflows = $outflows->merge($supplierPayments);

        // 3. Sales Returns (pengembalian uang ke pelanggan)
        // Note: Sales returns would need a separate action, using existing returns system

        return $outflows;
    }

    /**
     * Generate summary by category.
     *
     * @return array<string, array{in: int, out: int, net: int, count: int}>
     */
    public function getSummaryByCategory(array $options = []): array
    {
        $data = $this->handle($options);

        $summary = $data->groupBy('category')->map(function ($items, $category) {
            $in = $items->where('type', 'in')->sum('amount_minor');
            $out = $items->where('type', 'out')->sum('amount_minor');

            return [
                'in' => $in,
                'out' => $out,
                'net' => $in - $out,
                'count' => $items->count(),
            ];
        });

        return $summary->toArray();
    }

    /**
     * Generate daily cash flow for chart.
     *
     * @return array{labels: list<string>, series_in: list<int>, series_out: list<int>, series_net: list<int>}
     */
    public function getDailyChartData(array $options = []): array
    {
        $data = $this->handle($options);

        $daily = $data->groupBy('date')->map(function ($items, $date) {
            $in = $items->where('type', 'in')->sum('amount_minor');
            $out = $items->where('type', 'out')->sum('amount_minor');

            return [
                'date' => $date,
                'in' => $in,
                'out' => $out,
                'net' => $in - $out,
            ];
        })->sortKeys()->values();

        return [
            'labels' => $daily->map(fn ($d) => Carbon::parse($d['date'])->format('d M'))->toArray(),
            'series_in' => $daily->map(fn ($d) => $d['in'])->toArray(),
            'series_out' => $daily->map(fn ($d) => $d['out'])->toArray(),
            'series_net' => $daily->map(fn ($d) => $d['net'])->toArray(),
        ];
    }

    /**
     * Get overall totals.
     *
     * @return array{total_in: int, total_out: int, net: int}
     */
    public function getTotals(array $options = []): array
    {
        $data = $this->handle($options);

        $totalIn = $data->where('type', 'in')->sum('amount_minor');
        $totalOut = $data->where('type', 'out')->sum('amount_minor');

        return [
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'net' => $totalIn - $totalOut,
        ];
    }
}
