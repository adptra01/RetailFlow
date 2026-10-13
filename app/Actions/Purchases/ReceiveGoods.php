<?php

namespace App\Actions\Purchases;

use App\Enums\AuditAction;
use App\Enums\SyncOperationType;
use App\Exceptions\Purchases\InvalidReceiveQuantity;
use App\Exceptions\Purchases\PurchaseOrderClosed;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Domain entry point for receiving goods against a purchase order.
 *
 * Guarantees:
 *  - atomicity: receipt, items, stock movements, PO status update commit together;
 *  - idempotency: replaying the same client_request_id returns the original receipt;
 *  - stock integrity: stock is incremented via StockMovement ledger entries;
 *  - traceability: audit log entry is created.
 */
class ReceiveGoods
{
    /**
     * @param  array{
     *     purchase_order_id: int,
     *     user_id: int,
     *     items: list<array{
     *         purchase_order_item_id: int,
     *         quantity: int,
     *         notes?: string|null,
     *     }>,
     *     notes?: string|null,
     *     client_request_id?: string|null,
     *     tenant_id?: string|null,
     *     location_id?: string|null,
     *     device_id?: string|null,
     *     enqueue_for_sync?: bool|null,
     * } $data
     */
    public function handle(array $data): GoodsReceipt
    {
        $clientRequestId = $data['client_request_id'] ?? null;

        // §12: kunci idempotensi dibatasi tenant pemanggil.
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        if (is_string($clientRequestId) && $clientRequestId !== '') {
            $existing = $this->findByIdempotencyKey($clientRequestId, $tenantId);
            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(fn () => $this->persist($data, $clientRequestId));
        } catch (QueryException $e) {
            if ($this->isIdempotencyViolation($e) && is_string($clientRequestId) && $clientRequestId !== '') {
                return $this->findByIdempotencyKey($clientRequestId, $tenantId) ?? throw $e;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, ?string $clientRequestId): GoodsReceipt
    {
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        /** @var PurchaseOrder|null $po */
        $po = PurchaseOrder::query()
            ->with(['items.product', 'items.productUnit'])
            ->whereKey((int) $data['purchase_order_id'])
            ->lockForUpdate()
            ->first();

        if (! $po) {
            throw new PurchaseOrderClosed('Purchase order tidak ditemukan.');
        }

        if ($po->status === 'received' || $po->status === 'cancelled') {
            throw new PurchaseOrderClosed('PO sudah selesai atau dibatalkan.');
        }

        $items = $this->normalizeItems($data['items'], $po);

        $receipt = GoodsReceipt::create([
            'purchase_order_id' => $po->id,
            'user_id' => $data['user_id'],
            'notes' => $data['notes'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'client_request_id' => $clientRequestId,
            'tenant_id' => $tenantId,
        ]);

        foreach ($items as $item) {
            $poItem = $item['po_item'];
            $receiveQty = $item['quantity'];

            if ($receiveQty <= 0) {
                continue;
            }

            $available = $poItem->quantity - $poItem->received_quantity;
            if ($receiveQty > $available) {
                throw new InvalidReceiveQuantity(
                    "Qty penerimaan melebihi sisa PO item (maks: {$available})."
                );
            }

            $unitPriceMinor = $poItem->unit_price_minor;
            $subtotalMinor = $unitPriceMinor * $receiveQty;

            GoodsReceiptItem::create([
                'goods_receipt_id' => $receipt->id,
                'purchase_order_item_id' => $poItem->id,
                'quantity' => $receiveQty,
                'unit_price_minor' => $unitPriceMinor,
                'subtotal_minor' => $subtotalMinor,
                'notes' => $item['notes'] ?? null,
                'tenant_id' => $tenantId,
            ]);

            $poItem->increment('received_quantity', $receiveQty);

            $product = $poItem->product;
            if ($product && ! $product->is_unlimited_stock) {
                $conversionFactor = $poItem->productUnit?->conversion_factor ?? 1;
                $baseQty = $receiveQty * $conversionFactor;
                $before = $product->stock;
                $product->increment('stock', $baseQty);

                StockMovement::create([
                    'product_id' => $product->id,
                    'quantity_change' => $baseQty,
                    'before_stock' => $before,
                    'after_stock' => $product->stock,
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receipt->id,
                    'user_id' => $data['user_id'],
                    'notes' => 'Goods receipt '.$receipt->receipt_number,
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        $fresh = $po->fresh();
        $allReceived = $fresh->items->every(fn ($i) => $i->received_quantity >= $i->quantity);
        $anyReceived = $fresh->items->sum('received_quantity') > 0;

        if ($allReceived) {
            $fresh->update(['status' => 'received']);
        } elseif ($anyReceived) {
            $fresh->update(['status' => 'partial']);
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'],
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::GoodsReceived->value,
            'entity_type' => 'goods_receipt',
            'entity_id' => $receipt->uuid,
            'metadata' => [
                'client_request_id' => $clientRequestId,
                'receipt_number' => $receipt->receipt_number,
                'purchase_order_id' => $po->uuid,
                'items_count' => count($items),
            ],
        ]);

        $this->enqueueForSync($data, $receipt, $po, $items, $tenantId, $clientRequestId);

        return $receipt->load('items');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{purchase_order_item_id: int, quantity: int, notes: string|null, po_item: PurchaseOrderItem}>
     */
    private function normalizeItems(mixed $rawItems, PurchaseOrder $po): array
    {
        if (! is_array($rawItems) || count($rawItems) === 0) {
            throw new \InvalidArgumentException('Items penerimaan tidak boleh kosong.');
        }

        $normalized = [];
        $poItems = $po->items->keyBy('id');

        foreach ($rawItems as $item) {
            $poItemId = (int) ($item['purchase_order_item_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($poItemId <= 0 || $quantity <= 0) {
                throw new \InvalidArgumentException('Data item penerimaan tidak valid.');
            }

            $poItem = $poItems->get($poItemId);
            if (! $poItem) {
                throw new \InvalidArgumentException("PO item #{$poItemId} tidak ditemukan.");
            }

            $normalized[] = [
                'purchase_order_item_id' => $poItemId,
                'quantity' => $quantity,
                'notes' => $item['notes'] ?? null,
                'po_item' => $poItem,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{purchase_order_item_id: int, quantity: int, notes: string|null, po_item: PurchaseOrderItem}>  $items
     */
    private function enqueueForSync(
        array $data,
        GoodsReceipt $receipt,
        PurchaseOrder $po,
        array $items,
        ?string $tenantId,
        ?string $clientRequestId,
    ): void {
        $deviceId = $data['device_id'] ?? null;

        if ($deviceId === null || $tenantId === null) {
            return;
        }

        if (($data['enqueue_for_sync'] ?? true) === false) {
            return;
        }

        (new Outbox($tenantId))->enqueue(
            (string) $deviceId,
            'goods_receipt',
            $receipt->uuid,
            SyncOperationType::Create,
            [
                'purchase_order_id' => $po->uuid,
                'user_id' => $data['user_id'],
                'notes' => $data['notes'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'items' => array_map(static fn (array $item): array => [
                    'purchase_order_item_id' => $item['purchase_order_item_id'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'],
                ], $items),
            ],
            $clientRequestId,
        );
    }

    private function findByIdempotencyKey(string $clientRequestId, ?string $tenantId = null): ?GoodsReceipt
    {
        return GoodsReceipt::query()
            ->withoutGlobalScopes()
            ->where('client_request_id', $clientRequestId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    private function isIdempotencyViolation(QueryException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $code === 1062
            || $code === 1555
            || str_contains($e->getMessage(), 'client_request_id');
    }
}
