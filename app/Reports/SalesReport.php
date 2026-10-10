<?php

namespace App\Reports;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan penjualan harian & rentang tanggal (FR-09; use case `GenerateReport` §10.2).
 *
 * Definisi metrik (FR-09: "Omzet, penerimaan kas, piutang, dan laba tidak
 * boleh diperlakukan sebagai angka yang sama"):
 *  - omzet_minor        = Σ total_minor transaksi periode (termasuk kredit);
 *  - penerimaan_minor   = Σ paid_minor transaksi periode (kas diterima di
 *    kasir; penagihan piutang via `receivable_payments` TIDAK tercampur);
 *  - piutang_baru_minor = Σ (total − paid) transaksi kredit yang terbentuk
 *    pada periode;
 *  - diskon_minor       = Σ diskon transaksi + Σ diskon item.
 *
 * Tenant: deterministik bila `tenantId` eksplisit (CLI/test); tanpa itu
 * mengikuti konteks request. Transaksi yang dihapus (SoftDeletes) tidak
 * dihitung. Bila `$actor` diberikan, izin `report.financial.view`
 * diperiksa (FR-12) dan akses dicatat ke audit (`report.financial.viewed`).
 */
class SalesReport
{
    private readonly AuditRecorder $audit;

    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?User $actor = null,
        ?AuditRecorder $audit = null,
    ) {
        $this->audit = $audit ?? new AuditRecorder($this->tenantId);
    }

    /**
     * Laporan satu hari (default: hari ini).
     *
     * @return array<string, mixed>
     */
    public function daily(?string $date = null): array
    {
        $date ??= now()->toDateString();

        return $this->range($date, $date);
    }

    /**
     * Laporan rentang tanggal (inklusif; default: hari ini).
     *
     * @return array<string, mixed>
     */
    public function range(?string $from = null, ?string $to = null): array
    {
        ActionAuthorizer::assertIf($this->actor, Permission::ViewFinancialReports);

        $from ??= now()->startOfDay()->toDateString();
        $to ??= now()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59';

        $transactions = $this->tenantQuery(Transaction::class)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $omzet = 0;
        $penerimaan = 0;
        $piutangBaru = 0;
        $diskonTxn = 0;
        $diskonCount = 0;
        $creditCount = 0;
        $methods = [];

        foreach ($transactions as $txn) {
            $omzet += $txn->total_minor;
            $penerimaan += $txn->paid_minor;
            $diskonTxn += $txn->discount_minor;

            if ($txn->discount_minor > 0) {
                $diskonCount++;
            }

            if ($txn->payment_status === 'credit') {
                $creditCount++;
                $piutangBaru += max(0, $txn->total_minor - $txn->paid_minor);
            }

            $method = $txn->payment_method ?: 'unknown';
            $methods[$method] ??= [
                'payment_method' => $method,
                'transaksi' => 0,
                'omzet_minor' => 0,
                'penerimaan_minor' => 0,
            ];
            $methods[$method]['transaksi']++;
            $methods[$method]['omzet_minor'] += $txn->total_minor;
            $methods[$method]['penerimaan_minor'] += $txn->paid_minor;
        }

        $itemDiscount = (int) $this->tenantQuery(TransactionItem::class)
            ->whereHas('transaction', fn (Builder $q) => $q->whereBetween('created_at', [$start, $end]))
            ->sum('discount_minor');

        $count = $transactions->count();

        // FR-12: akses laporan keuangan dicatat dengan aktor + periode —
        // hanya bila aktor nyata (CLI/otomasi tanpa aktor tidak dicatat).
        if ($this->actor !== null) {
            $this->audit->record(
                AuditAction::FinancialReportViewed,
                $this->actor,
                metadata: ['from' => $from, 'to' => $to],
            );
        }

        return [
            'from' => $from,
            'to' => $to,
            'omzet_minor' => $omzet,
            'penerimaan_minor' => $penerimaan,
            'piutang_baru_minor' => $piutangBaru,
            'transaksi_count' => $count,
            'transaksi_kredit_count' => $creditCount,
            'transaksi_berdiskon_count' => $diskonCount,
            'rata_rata_transaksi_minor' => $count > 0 ? (int) round($omzet / $count) : 0,
            'diskon_minor' => $diskonTxn + $itemDiscount,
            'metode_pembayaran' => array_values($methods),
            'produk_terlaris' => $this->topProducts($start, $end, 10),
            'stok_menipis' => $this->lowStock(50),
            'riwayat' => $transactions->map(function (Transaction $txn) {
                return [
                    'id' => $txn->id,
                    'invoice_number' => $txn->invoice_number,
                    'customer_name' => $txn->customer_name,
                    'payment_method' => $txn->payment_method,
                    'payment_status' => $txn->payment_status,
                    'total_minor' => $txn->total_minor,
                    'paid_minor' => $txn->paid_minor,
                    'change_minor' => $txn->change_minor,
                    'discount_minor' => $txn->discount_minor,
                    'created_at' => $txn->created_at?->toDateTimeString(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Definisi metrik yang wajib disebut laporan (FR-09).
     *
     * @return array<string, string>
     */
    public static function metricDefinitions(): array
    {
        return [
            'omzet_minor' => 'Nilai penjualan tercatat: Σ total_minor transaksi pada periode, termasuk penjualan kredit.',
            'penerimaan_minor' => 'Kas yang diterima di kasir: Σ paid_minor transaksi pada periode. Penagihan piutang (receivable_payments) TIDAK termasuk — laporan piutang terpisah.',
            'piutang_baru_minor' => 'Piutang yang baru terbentuk pada periode: Σ (total_minor − paid_minor) transaksi kredit.',
            'diskon_minor' => 'Total diskon yang diberikan pada periode: Σ diskon transaksi + Σ diskon item.',
            'rata_rata_transaksi_minor' => 'Omzet dibagi jumlah transaksi pada periode.',
        ];
    }

    /**
     * @return list<array{product_id: int, name: string, quantity: int, omzet_minor: int}>
     */
    private function topProducts(string $start, string $end, int $limit): array
    {
        $rows = $this->tenantQuery(TransactionItem::class)
            ->select('product_id', DB::raw('SUM(quantity) as quantity'), DB::raw('SUM(subtotal_minor) as omzet_minor'))
            ->whereHas('transaction', fn (Builder $q) => $q->whereBetween('created_at', [$start, $end]))
            ->groupBy('product_id')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = $this->tenantQuery(Product::class)
            ->whereIn('id', $rows->pluck('product_id'))
            ->pluck('name', 'id');

        return $rows->map(function ($row) use ($names) {
            return [
                'product_id' => (int) $row->product_id,
                'name' => (string) ($names[$row->product_id] ?? 'Produk #'.$row->product_id),
                'quantity' => (int) $row->quantity,
                'omzet_minor' => (int) $row->omzet_minor,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{product_id: int, name: string, stock: int, min_stock: int}>
     */
    private function lowStock(int $limit): array
    {
        return $this->tenantQuery(Product::class)
            ->where('is_unlimited_stock', false)
            ->where('min_stock', '>', 0)
            ->whereColumn('stock', '<=', 'min_stock')
            ->orderByRaw('stock - min_stock')
            ->limit($limit)
            ->get(['id', 'name', 'stock', 'min_stock'])
            ->map(fn (Product $product) => [
                'product_id' => $product->id,
                'name' => $product->name,
                'stock' => $product->stock,
                'min_stock' => $product->min_stock,
            ])
            ->all();
    }

    /**
     * @param  class-string  $model
     */
    private function tenantQuery(string $model): Builder
    {
        $query = $model::query();

        if ($this->tenantId !== null) {
            $query->withoutGlobalScope('tenant')
                ->where($query->getModel()->getTable().'.tenant_id', $this->tenantId);
        }

        return $query;
    }
}
