<?php

namespace App\Support\Sync\Handlers;

use App\Actions\Sales\CreateSale;
use App\Actions\Sync\RecordSyncConflict;
use App\Enums\SyncConflictType;
use App\Enums\SyncOperationType;
use App\Exceptions\Sales\InsufficientStock;
use App\Models\Product;
use App\Models\SyncInbox;
use App\Models\Transaction;
use App\Support\Sync\SyncApplicationResult;
use App\Support\Sync\SyncOperationHandler;
use Throwable;

/**
 * Menerapkan operasi penjualan dari perangkat ke domain (§11.3 langkah 6).
 *
 * Idempotensi: `operation_id` dipakai sebagai `client_request_id` pada
 * `CreateSale`, sehingga pengiriman ulang / penerapan ulang mengembalikan
 * penjualan yang sama alih-alih menggandakannya (§11.1).
 *
 * Konflik stok (§11.4): penjualan perangkat TIDAK dihapus diam-diam.
 * - Kebijakan membatasi penjualan (default): operasi tidak diterapkan, kasus
 *   konflik terbuka dibuat, status `conflict`.
 * - Kebijakan mengizinkan stok negatif: penjualan diterapkan dan stok boleh
 *   negatif, kasus konflik tetap dicatat agar tidak disembunyikan.
 *
 * Pelanggaran aturan lain (mis. tanpa pelanggan untuk kredit) → `rejected`.
 */
final class SaleSyncHandler implements SyncOperationHandler
{
    public function __construct(private readonly ?RecordSyncConflict $conflicts = null) {}

    public function supports(SyncInbox $operation): bool
    {
        return $operation->entity_type === 'sale'
            && $operation->operation_type === SyncOperationType::Create->value;
    }

    public function apply(SyncInbox $operation): SyncApplicationResult
    {
        $payload = $operation->payload ?? [];

        if (! isset($payload['user_id'])) {
            return SyncApplicationResult::rejected('Operasi penjualan tanpa user_id.');
        }

        $allowNegativeStock = (bool) config('pos.inventory.allow_negative_stock', false);

        // Identitas tenant/perangkat selalu dari server, bukan payload (§12).
        $data = $payload;
        $data['tenant_id'] = $operation->tenant_id;
        $data['device_id'] = $operation->device_id;
        $data['client_request_id'] = $operation->operation_id;
        $data['allow_negative_stock'] = $allowNegativeStock;

        try {
            $transaction = (new CreateSale)->handle($data);
        } catch (InsufficientStock $e) {
            $this->recordInsufficientStockConflict($operation, $e);

            return SyncApplicationResult::conflict($e->getMessage());
        } catch (Throwable $e) {
            return SyncApplicationResult::rejected($e->getMessage());
        }

        // Meski diterapkan, stok negatif/ketidaksesuaian tetap dilaporkan
        // sebagai kasus terbuka (§11.4).
        $this->recordNegativeStockConflict($operation, $transaction);

        return SyncApplicationResult::applied($transaction->uuid);
    }

    private function recordInsufficientStockConflict(SyncInbox $operation, InsufficientStock $e): void
    {
        $shortfall = max(0, $e->requested - $e->available);

        $this->conflicts()->handle($operation, SyncConflictType::Stock, [
            'reason' => 'insufficient_stock',
            'product_id' => $e->productId,
            'available' => $e->available,
            'requested' => $e->requested,
            'shortfall' => $shortfall,
            'detected_at' => now()->toIso8601String(),
        ]);
    }

    private function recordNegativeStockConflict(SyncInbox $operation, Transaction $transaction): void
    {
        $products = Product::query()
            ->whereKey($transaction->items()->pluck('product_id')->all())
            ->get()
            ->filter(fn (Product $product) => $product->stock < 0)
            ->map(fn (Product $product) => [
                'product_id' => $product->id,
                'after_stock' => $product->stock,
            ])
            ->values()
            ->all();

        if ($products === []) {
            return;
        }

        $this->conflicts()->handle($operation, SyncConflictType::Stock, [
            'reason' => 'negative_stock_applied',
            'products' => $products,
            'detected_at' => now()->toIso8601String(),
        ], $transaction->uuid);
    }

    private function conflicts(): RecordSyncConflict
    {
        return $this->conflicts ?? app(RecordSyncConflict::class);
    }
}
