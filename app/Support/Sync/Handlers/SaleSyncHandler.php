<?php

namespace App\Support\Sync\Handlers;

use App\Actions\Sales\CreateSale;
use App\Actions\Sales\VoidSale;
use App\Actions\Sync\RecordSyncConflict;
use App\Enums\SyncConflictType;
use App\Enums\SyncOperationType;
use App\Exceptions\Sales\InsufficientStock;
use App\Exceptions\Sales\VoidNotAllowed;
use App\Models\Product;
use App\Models\SyncInbox;
use App\Models\Transaction;
use App\Support\Sync\SyncApplicationResult;
use App\Support\Sync\SyncOperationHandler;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menerapkan operasi penjualan dari perangkat ke domain (§11.3 langkah 6).
 *
 * Operasi yang didukung:
 *  - `sale`/`create` — penjualan baru lewat `CreateSale`;
 *  - `sale`/`update` — **pembatalan terkontrol (void)** lewat `VoidSale`
 *    (§17.4), sehingga pembatalan yang dilakukan perangkat tanpa jaringan
 *    tetap sah dan idempoten saat terkirim.
 *
 * Idempotensi: `operation_id` dipakai sebagai `client_request_id` pada
 * `CreateSale`, sehingga pengiriman ulang / penerapan ulang mengembalikan
 * penjualan yang sama alih-alih menggandakannya (§11.1). Untuk void,
 * idempotensi dijaga oleh state terminal `payment_status = 'void'`: operasi
 * yang diterapkan ulang tidak mengembalikan stok dua kali (§17.4).
 *
 * Konflik stok (§11.4): penjualan perangkat TIDAK dihapus diam-diam.
 * - Kebijakan membatasi penjualan (default): operasi tidak diterapkan, kasus
 *   konflik terbuka dibuat, status `conflict`.
 * - Kebijakan mengizinkan stok negatif: penjualan diterapkan dan stok boleh
 *   negatif, kasus konflik tetap dicatat agar tidak disembunyikan.
 *
 * Pelanggaran aturan lain (mis. tanpa pelanggan untuk kredit, void tanpa
 * alasan, penjualan yang belum ada di server) → `rejected`.
 */
final class SaleSyncHandler implements SyncOperationHandler
{
    public function __construct(private readonly ?RecordSyncConflict $conflicts = null) {}

    public function supports(SyncInbox $operation): bool
    {
        return $operation->entity_type === 'sale'
            && in_array($operation->operation_type, [
                SyncOperationType::Create->value,
                SyncOperationType::Update->value,
            ], true);
    }

    public function apply(SyncInbox $operation): SyncApplicationResult
    {
        return match ($operation->operation_type) {
            SyncOperationType::Create->value => $this->applyCreate($operation),
            SyncOperationType::Update->value => $this->applyVoid($operation),
            default => SyncApplicationResult::rejected('Jenis operasi penjualan tidak dikenal.'),
        };
    }

    private function applyCreate(SyncInbox $operation): SyncApplicationResult
    {
        $payload = $operation->payload ?? [];

        if (! isset($payload['user_id'])) {
            return SyncApplicationResult::rejected('Operasi penjualan tanpa user_id.');
        }

        // §17.1/§11.1: `entity_id` adalah identitas stabil penjualan milik
        // perangkat. Bila penjualan dengan identitas itu sudah ada, operasi ini
        // adalah penerapan ulang (idempoten) — kembalikan record yang sama
        // alih-alih membuat penjualan kedua.
        if (! Str::isUuid((string) $operation->entity_id)) {
            return SyncApplicationResult::rejected('entity_id penjualan harus berupa UUID.');
        }

        $existing = Transaction::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('uuid', $operation->entity_id)
            ->first();

        if ($existing !== null) {
            return SyncApplicationResult::applied($existing->uuid);
        }

        $allowNegativeStock = (bool) config('pos.inventory.allow_negative_stock', false);

        // Identitas tenant/perangkat selalu dari server, bukan payload (§12).
        $data = $payload;
        $data['tenant_id'] = $operation->tenant_id;
        $data['device_id'] = $operation->device_id;
        $data['client_request_id'] = $operation->operation_id;
        $data['uuid'] = (string) $operation->entity_id;
        $data['allow_negative_stock'] = $allowNegativeStock;
        // Jangan memantulkan operasi perangkat kembali ke outbox server (§11.1).
        $data['enqueue_for_sync'] = false;

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

    /**
     * Pembatalan terkontrol dari perangkat (§17.4).
     *
     * Kontrak payload (dihasilkan `VoidSale::enqueueForSync`):
     * `{ void: true, user_id, reason, restore_stock }`.
     *
     * §11.3 langkah 5 (validasi urutan): pembatalan hanya sah bila
     * penjualannya sudah ada di server. Perangkat yang membatalkan penjualan
     * yang belum terkirim harus mengirim penjualan itu lebih dahulu — dalam
     * satu batch, urutan `local_sequence`/`id` inbox menjamin create diterapkan
     * sebelum update.
     */
    private function applyVoid(SyncInbox $operation): SyncApplicationResult
    {
        $payload = $operation->payload ?? [];

        $declaresVoid = ($payload['void'] ?? null) === true
            || ($payload['payment_status'] ?? null) === 'void';

        if (! $declaresVoid) {
            return SyncApplicationResult::rejected(
                'Operasi ubah penjualan hanya didukung untuk pembatalan (void).'
            );
        }

        if (! isset($payload['user_id'])) {
            return SyncApplicationResult::rejected('Operasi pembatalan penjualan tanpa user_id.');
        }

        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($reason === '') {
            return SyncApplicationResult::rejected('Pembatalan penjualan memerlukan alasan (§17.4).');
        }

        // Identitas tenant/perangkat selalu dari server, bukan payload (§12).
        $transaction = Transaction::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('uuid', $operation->entity_id)
            ->first();

        if ($transaction === null) {
            return SyncApplicationResult::rejected(
                'Penjualan yang akan dibatalkan belum ada di server; kirim penjualannya lebih dahulu.'
            );
        }

        try {
            (new VoidSale)->handle([
                'transaction_id' => $transaction->id,
                'user_id' => $payload['user_id'],
                'reason' => $reason,
                'tenant_id' => $operation->tenant_id,
                'device_id' => $operation->device_id,
                'restore_stock' => array_key_exists('restore_stock', $payload)
                    ? (bool) $payload['restore_stock']
                    : null,
                'client_request_id' => $operation->operation_id,
                // Jangan memantulkan operasi perangkat kembali ke outbox (§11.1).
                'enqueue_for_sync' => false,
            ]);
        } catch (VoidNotAllowed $e) {
            return SyncApplicationResult::rejected($e->getMessage());
        } catch (Throwable $e) {
            return SyncApplicationResult::rejected($e->getMessage());
        }

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
