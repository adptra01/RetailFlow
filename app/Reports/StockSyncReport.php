<?php

namespace App\Reports;

use App\Enums\Permission;
use App\Enums\SyncConflictStatus;
use App\Enums\SyncConflictType;
use App\Enums\SyncInboxStatus;
use App\Enums\SyncOperationType;
use App\Models\Product;
use App\Models\SyncConflict;
use App\Models\SyncInbox;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan stok: memisahkan stok tersinkron dan stok fisik yang diperkirakan
 * (§11.4 "Laporan harus membedakan stok fisik yang diperkirakan dan stok
 * tersinkron").
 *
 * Definisi:
 * - `stock_tersinkron` — nilai ledger server (`products.stock`), otoritatif
 *   untuk data yang sudah diterapkan;
 * - `mutasi_tertunda`  — jumlah bersih unit yang masih tertahan pada operasi
 *   penjualan inbox yang belum diterapkan (`received` atau `conflict`);
 * - `stock_diperkirakan` = `stock_tersinkron` − `mutasi_tertunda`, yaitu
 *   perkiraan stok fisik jika semua operasi tertunda akhirnya diterapkan.
 *
 * Laporan juga menampilkan jumlah kasus konflik terbuka per produk sehingga
 * ketidaksesuaian tidak tersembunyi.
 *
 * Tenant: deterministik bila `tenantId` eksplisit; tanpa itu mengikuti
 * konteks request. Bila `$actor` diberikan, izin `report.financial.view`
 * diperiksa (FR-12) — laporan ini memuat nilai persediaan.
 */
class StockSyncReport
{
    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?User $actor = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(int $limit = 200): array
    {
        ActionAuthorizer::assertIf($this->actor, Permission::ViewFinancialReports);

        $pendingDeltas = $this->pendingStockDeltas();
        $openConflicts = $this->openConflictCounts();

        $rows = $this->tenantQuery(Product::class)
            ->where('is_unlimited_stock', false)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'stock', 'min_stock'])
            ->map(function (Product $product) use ($pendingDeltas, $openConflicts): array {
                $pending = $pendingDeltas[$product->id] ?? 0;

                return [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock_tersinkron' => $product->stock,
                    'mutasi_tertunda' => $pending,
                    'stock_diperkirakan' => $product->stock - $pending,
                    'min_stock' => $product->min_stock,
                    'konflik_terbuka' => $openConflicts[$product->id] ?? 0,
                ];
            })
            ->values()
            ->all();

        return [
            'products' => $rows,
            'kasus_konflik_terbuka' => array_sum($openConflicts),
            'operasi_tertunda' => array_sum($pendingDeltas),
        ];
    }

    /**
     * Daftar kasus konflik stok terbuka (§11.3 langkah 11).
     *
     * @return list<array<string, mixed>>
     */
    public function openConflicts(int $limit = 200): array
    {
        ActionAuthorizer::assertIf($this->actor, Permission::ViewFinancialReports);

        return $this->tenantQuery(SyncConflict::class)
            ->ofType(SyncConflictType::Stock)
            ->where('status', SyncConflictStatus::Open->value)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (SyncConflict $conflict): array => [
                'id' => $conflict->id,
                'uuid' => $conflict->uuid,
                'device_id' => $conflict->device_id,
                'operation_id' => $conflict->operation_id,
                'entity_id' => $conflict->entity_id,
                'details' => $conflict->details,
                'created_at' => $conflict->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Definisi metrik (agar laporan tidak menyamakan dua angka berbeda).
     *
     * @return array<string, string>
     */
    public static function metricDefinitions(): array
    {
        return [
            'stock_tersinkron' => 'Nilai ledger server (products.stock) — otoritatif untuk data yang sudah diterapkan.',
            'mutasi_tertunda' => 'Jumlah bersih unit pada operasi penjualan inbox yang belum diterapkan (received/conflict).',
            'stock_diperkirakan' => 'Perkiraan stok fisik: stock_tersinkron − mutasi_tertunda.',
        ];
    }

    /**
     * Jumlah bersih unit yang tertahan per produk dari operasi penjualan yang
     * belum diterapkan (`received` atau `conflict`).
     *
     * @return array<int, int>
     */
    private function pendingStockDeltas(): array
    {
        $deltas = [];

        $operations = $this->tenantQuery(SyncInbox::class)
            ->whereIn('status', [SyncInboxStatus::Received->value, SyncInboxStatus::Conflict->value])
            ->where('entity_type', 'sale')
            ->where('operation_type', SyncOperationType::Create->value)
            ->get(['payload']);

        foreach ($operations as $operation) {
            foreach ($operation->payload['items'] ?? [] as $item) {
                $productId = (int) ($item['product_id'] ?? 0);

                if ($productId <= 0) {
                    continue;
                }

                $factor = (float) ($item['conversion_factor'] ?? 1);
                $quantity = (int) ($item['quantity'] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                $base = max(1, (int) round($quantity * $factor));
                $deltas[$productId] = ($deltas[$productId] ?? 0) + $base;
            }
        }

        return $deltas;
    }

    /**
     * @return array<int, int>
     */
    private function openConflictCounts(): array
    {
        $counts = [];

        $conflicts = $this->tenantQuery(SyncConflict::class)
            ->ofType(SyncConflictType::Stock)
            ->where('status', SyncConflictStatus::Open->value)
            ->get(['details']);

        foreach ($conflicts as $conflict) {
            $productId = (int) ($conflict->details['product_id']
                ?? ($conflict->details['products'][0]['product_id'] ?? 0));

            if ($productId <= 0) {
                continue;
            }

            $counts[$productId] = ($counts[$productId] ?? 0) + 1;
        }

        return $counts;
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
