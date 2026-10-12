<?php

namespace App\Actions\Reports;

use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\TransactionItem;
use Illuminate\Support\Collection;

/**
 * Laporan Perbandingan Pembelian vs Penjualan (Purchase vs Sales Report).
 *
 * Membandingkan volume & nilai pembelian vs penjualan per produk,
 * untuk identifikasi stok bergerak lambat, margin per produk, dll.
 */
class GeneratePurchaseVsSalesReport
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

        // Get sales data per product
        $salesData = $this->getSalesData($tenantId, $fromDate, $toDate);

        // Get purchase data per product
        $purchaseData = $this->getPurchaseData($tenantId, $fromDate, $toDate);

        // Merge and compare
        $allProductIds = $salesData->keys()->merge($purchaseData->keys())->unique();

        return $allProductIds->map(function ($productId) use ($salesData, $purchaseData) {
            $sale = $salesData->get($productId, [
                'qty' => 0, 'revenue' => 0, 'cogs' => 0, 'margin' => 0,
            ]);
            $purchase = $purchaseData->get($productId, [
                'qty' => 0, 'cost' => 0, 'avg_cost' => 0,
            ]);

            $revenue = $sale['revenue'];
            $cogs = $sale['cogs'];
            $purchaseCost = $purchase['cost'];
            $purchaseQty = $purchase['qty'];

            $margin = $revenue - $cogs;
            $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 2) : 0;

            // Calculate inventory turnover indicator
            $avgStock = Product::query()->whereKey($productId)->value('stock') ?? 0;
            $turnover = $avgStock > 0 ? round($sale['qty'] / $avgStock, 2) : 0;

            return [
                'product_id' => $productId,
                'product_name' => $sale['name'] ?? $purchase['name'] ?? 'Unknown',
                'product_sku' => $sale['sku'] ?? $purchase['sku'] ?? '-',
                // Sales
                'sales_qty' => $sale['qty'],
                'sales_revenue' => $revenue,
                'sales_cogs' => $cogs,
                'sales_margin' => $margin,
                'sales_margin_pct' => $marginPct,
                // Purchases
                'purchase_qty' => $purchaseQty,
                'purchase_cost' => $purchaseCost,
                'purchase_avg_cost' => $purchaseQty > 0 ? round($purchaseCost / $purchaseQty, 2) : 0,
                // Comparison
                'qty_diff' => $purchaseQty - $sale['qty'],  // positive = stock building up
                'turnover_ratio' => $turnover,
                'stock_status' => $this->getStockStatus($sale['qty'], $purchaseQty, $avgStock),
            ];
        })->sortByDesc('sales_revenue')->values();
    }

    private function getSalesData(?string $tenantId, ?string $fromDate, ?string $toDate): Collection
    {
        $query = TransactionItem::query()
            ->selectRaw('
                products.id as product_id,
                products.name as name,
                products.sku as sku,
                SUM(transaction_items.quantity) as qty,
                SUM(transaction_items.subtotal) as revenue,
                SUM(products.buy_price * transaction_items.quantity) as cogs
            ')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->where('transactions.payment_status', '!=', 'void')
            ->where('transactions.payment_status', '!=', 'cancelled')
            ->groupBy('products.id', 'products.name', 'products.sku');

        if ($tenantId) {
            $query->where('transactions.tenant_id', $tenantId);
        }

        if ($fromDate) {
            $query->whereDate('transactions.created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('transactions.created_at', '<=', $toDate);
        }

        return $query->get()->keyBy('product_id');
    }

    private function getPurchaseData(?string $tenantId, ?string $fromDate, ?string $toDate): Collection
    {
        $query = PurchaseOrderItem::query()
            ->selectRaw('
                products.id as product_id,
                products.name as name,
                products.sku as sku,
                SUM(purchase_order_items.quantity) as qty,
                SUM(purchase_order_items.subtotal_minor) as cost
            ')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->join('products', 'purchase_order_items.product_id', '=', 'products.id')
            ->whereIn('purchase_orders.status', ['received', 'partial'])
            ->groupBy('products.id', 'products.name', 'products.sku');

        if ($tenantId) {
            $query->where('purchase_orders.tenant_id', $tenantId);
        }

        if ($fromDate) {
            $query->whereDate('purchase_orders.created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('purchase_orders.created_at', '<=', $toDate);
        }

        return $query->get()->keyBy('product_id');
    }

    private function getStockStatus(int $salesQty, int $purchaseQty, int $currentStock): string
    {
        if ($salesQty === 0 && $purchaseQty === 0) {
            return 'No Activity';
        }

        if ($salesQty === 0 && $purchaseQty > 0) {
            return 'Dead Stock';
        }

        if ($purchaseQty === 0 && $salesQty > 0) {
            return 'Stock Out Risk';
        }

        $diff = $purchaseQty - $salesQty;
        if ($diff > 0 && $currentStock > 0) {
            return 'Overstock';
        }

        if ($diff < 0 && $currentStock <= 5) {
            return 'Understock';
        }

        return 'Healthy';
    }

    /**
     * Get chart data for top products by revenue vs cost.
     *
     * @return array{labels: list<string>, series_revenue: list<float>, series_purchase_cost: list<float>}
     */
    public function getChartData(array $options = [], int $limit = 10): array
    {
        $data = $this->handle($options)->take($limit);

        return [
            'labels' => $data->map(fn ($d) => $d['product_name'])->toArray(),
            'series_revenue' => $data->map(fn ($d) => $d['sales_revenue'])->toArray(),
            'series_purchase_cost' => $data->map(fn ($d) => $d['purchase_cost'])->toArray(),
            'series_sales_qty' => $data->map(fn ($d) => $d['sales_qty'])->toArray(),
            'series_purchase_qty' => $data->map(fn ($d) => $d['purchase_qty'])->toArray(),
        ];
    }

    /**
     * Get summary by stock status.
     *
     * @return array<string, array{count: int, total_revenue: float, total_purchase: float}>
     */
    public function getStatusSummary(array $options = []): array
    {
        $data = $this->handle($options);

        return $data->groupBy('stock_status')->map(function ($items, $status) {
            return [
                'count' => $items->count(),
                'total_revenue' => $items->sum('sales_revenue'),
                'total_purchase' => $items->sum('purchase_cost'),
                'total_qty_diff' => $items->sum('qty_diff'),
            ];
        })->toArray();
    }
}
