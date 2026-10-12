<?php

namespace App\Support\Sync\Handlers;

use App\Actions\Sales\CreateSalesReturn;
use App\Enums\SyncOperationType;
use App\Exceptions\Sales\ReturnNotAllowed;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\SyncInbox;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\Sync\SyncApplicationResult;
use App\Support\Sync\SyncOperationHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Penerapan retur penjualan dari perangkat ke domain (§11.1–§11.3, §17.4).
 *
 * Perangkat yang menerima pengembalian barang tanpa jaringan harus dapat
 * mengirim operasinya, dan penerapan di server harus menghasilkan dokumen
 * retur yang sama dengan jalur web: alasan wajib, jumlah tidak melebihi nota,
 * stok hanya kembali sesuai kebijakan lewat mutasi yang dapat ditelusuri,
 * piutang dikoreksi lebih dahulu, sisanya refund.
 *
 * Kontrak payload (dihasilkan `CreateSalesReturn::enqueueForSync`):
 * `{ transaction_uuid, user_id, reason, restore_stock, items: [{ transaction_item_id, transaction_item_uuid?, quantity, reason? }] }`.
 *
 * Identitas:
 *  - `entity_id` operasi adalah **uuid dokumen retur milik perangkat** dan
 *    diadopsi server (§17.1), sehingga operasi lanjutan yang menunjuk retur
 *    itu tetap sah;
 *  - baris dapat dirujuk lewat `transaction_item_uuid`, `transaction_item_id`,
 *    atau `product_id` (+ `product_unit_id`) — yang paling tersedia bagi
 *    perangkat yang belum pernah menarik id server (§11.3 langkah 9–10);
 *  - tenant & perangkat selalu dari server, bukan payload (§12).
 *
 * Idempotensi (§11.1) dijaga berlapis: `operation_id` unik di `sync_inbox`,
 * status terminal `applied`, dan `client_request_id = operation_id` pada
 * `CreateSalesReturn` — penerapan ulang mengembalikan dokumen yang sama
 * sehingga **stok tidak dikembalikan dua kali** (§17.4).
 */
final class SaleReturnSyncHandler implements SyncOperationHandler
{
    public function supports(SyncInbox $operation): bool
    {
        return $operation->entity_type === 'sale_return'
            && $operation->operation_type === SyncOperationType::Create->value;
    }

    public function apply(SyncInbox $operation): SyncApplicationResult
    {
        $payload = $operation->payload ?? [];

        if (! isset($payload['user_id'])) {
            return SyncApplicationResult::rejected('Operasi retur penjualan tanpa user_id.');
        }

        // §17.1: identitas dokumen retur milik perangkat harus stabil & sah.
        if (! Str::isUuid((string) $operation->entity_id)) {
            return SyncApplicationResult::rejected('entity_id retur penjualan harus berupa UUID.');
        }

        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($reason === '') {
            return SyncApplicationResult::rejected('Retur penjualan memerlukan alasan (§17.4/FR-08).');
        }

        // Penerapan ulang (§11.1): dokumen dengan identitas itu sudah ada.
        $existing = SalesReturn::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('uuid', $operation->entity_id)
            ->first();

        if ($existing !== null) {
            return SyncApplicationResult::applied($existing->uuid);
        }

        $transaction = $this->resolveTransaction($operation, (string) ($payload['transaction_uuid'] ?? ''));

        if ($transaction === null) {
            return SyncApplicationResult::rejected(
                'Penjualan yang akan diretur belum ada di server; kirim penjualannya lebih dahulu.'
            );
        }

        $items = $this->resolveItems($transaction, $payload['items'] ?? []);

        if ($items === null) {
            return SyncApplicationResult::rejected(
                'Baris retur tidak dapat dicocokkan dengan penjualan di server.'
            );
        }

        try {
            $return = (new CreateSalesReturn)->handle([
                'transaction_id' => $transaction->id,
                'uuid' => (string) $operation->entity_id,
                'user_id' => (int) $payload['user_id'],
                'reason' => $reason,
                'items' => $items,
                'restore_stock' => array_key_exists('restore_stock', $payload)
                    ? (bool) $payload['restore_stock']
                    : null,
                'tenant_id' => $operation->tenant_id,
                'device_id' => $operation->device_id,
                'client_request_id' => $operation->operation_id,
                // Jangan memantulkan operasi perangkat kembali ke outbox (§11.1).
                'enqueue_for_sync' => false,
            ]);
        } catch (ReturnNotAllowed $e) {
            return SyncApplicationResult::rejected($e->getMessage());
        } catch (Throwable $e) {
            return SyncApplicationResult::rejected($e->getMessage());
        }

        return SyncApplicationResult::applied($return->uuid);
    }

    /**
     * §11.3 langkah 5 (validasi urutan): retur hanya sah bila penjualannya
     * sudah ada di server. Perangkat yang meretur penjualan yang belum
     * terkirim harus mengirim penjualan itu lebih dahulu — dalam satu batch,
     * urutan `local_sequence`/id inbox menjamin create diterapkan lebih dulu.
     */
    private function resolveTransaction(SyncInbox $operation, string $transactionUuid): ?Transaction
    {
        $query = Transaction::query()->where('tenant_id', $operation->tenant_id);

        if (Str::isUuid($transactionUuid)) {
            return $query->where('uuid', $transactionUuid)->first();
        }

        return null;
    }

    /**
     * Cocokkan baris retur perangkat dengan baris penjualan di server.
     *
     * Referensi diterima berlapis, dari yang paling tepat ke yang paling
     * tersedia bagi perangkat:
     *  1. `transaction_item_uuid` — identitas baris (bila perangkat memilikinya);
     *  2. `transaction_item_id` — id server (model sinkronisasi satu basis data);
     *  3. `product_id` (+ `product_unit_id`) — yang selalu diketahui perangkat;
     *     baris dipilih yang sisanya paling banyak (> 0), dan baris yang sudah
     *     dipakai baris lain dalam payload yang sama tidak dipakai ulang.
     *
     * @param  array<int, mixed>  $rawItems
     * @return list<array{transaction_item_id: int, quantity: int, reason: string|null}>|null
     */
    private function resolveItems(Transaction $transaction, array $rawItems): ?array
    {
        if ($rawItems === []) {
            return null;
        }

        $sold = TransactionItem::query()
            ->where('transaction_id', $transaction->id)
            ->get();

        $byId = $sold->keyBy('id');
        $byUuid = $sold->keyBy('uuid');

        $returned = SalesReturnItem::query()
            ->whereIn('transaction_item_id', $sold->pluck('id')->all())
            ->selectRaw('transaction_item_id, SUM(qty) as qty')
            ->groupBy('transaction_item_id')
            ->pluck('qty', 'transaction_item_id');

        $items = [];
        $usedIds = [];

        foreach ($rawItems as $raw) {
            if (! is_array($raw)) {
                return null;
            }

            $line = null;

            if (! empty($raw['transaction_item_uuid'])) {
                $line = $byUuid->get($raw['transaction_item_uuid']);
            }

            if ($line === null && ! empty($raw['transaction_item_id'])) {
                $line = $byId->get($raw['transaction_item_id']);
            }

            if ($line === null && ! empty($raw['product_id'])) {
                $line = $this->matchLineByProduct($sold, $raw, $usedIds, $returned);
            }

            if ($line === null) {
                return null;
            }

            $usedIds[] = $line->id;

            $items[] = [
                'transaction_item_id' => $line->id,
                'quantity' => (int) ($raw['quantity'] ?? 0),
                'reason' => isset($raw['reason']) ? (string) $raw['reason'] : null,
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<int, TransactionItem>  $sold
     * @param  array<string, mixed>  $raw
     * @param  list<int>  $usedIds
     * @param  Collection<int|string, mixed>  $returned
     */
    private function matchLineByProduct(Collection $sold, array $raw, array $usedIds, Collection $returned): ?TransactionItem
    {
        return $sold
            ->where('product_id', $raw['product_id'])
            ->when(
                ! empty($raw['product_unit_id']),
                fn ($lines) => $lines->where('product_unit_id', $raw['product_unit_id']),
            )
            ->reject(fn (TransactionItem $line) => in_array($line->id, $usedIds, true))
            ->filter(fn (TransactionItem $line) => ((int) $line->quantity - (int) ($returned[$line->id] ?? 0)) > 0)
            ->sortByDesc(fn (TransactionItem $line) => (int) $line->quantity - (int) ($returned[$line->id] ?? 0))
            ->first();
    }
}
