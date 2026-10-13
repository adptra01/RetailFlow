<?php

namespace App\Actions\Purchases;

use App\Enums\AuditAction;
use App\Enums\SyncOperationType;
use App\Exceptions\Purchases\InvalidReturnQuantity;
use App\Exceptions\Purchases\PurchaseOrderClosed;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Domain entry point for returning goods to a supplier (purchase return).
 *
 * Guarantees:
 *  - atomicity: return record, items, stock decrement, supplier balance update commit together;
 *  - idempotency: replaying the same client_request_id returns the original return;
 *  - stock integrity: stock is decremented via StockMovement ledger entries;
 *  - traceability: audit log entry is created;
 *  - supplier balance reflects the return.
 */
class CreatePurchaseReturn
{
    /**
     * @param  array{
     *     purchase_order_id: int,
     *     supplier_id: int,
     *     user_id: int,
     *     items: list<array{
     *         product_id: int,
     *         product_unit_id?: int|null,
     *         qty: int,
     *         price_minor: int,
     *         discount_minor?: int|null,
     *         reason?: string|null,
     *     }>,
     *     reason?: string|null,
     *     client_request_id?: string|null,
     *     tenant_id?: string|null,
     *     location_id?: string|null,
     *     device_id?: string|null,
     *     enqueue_for_sync?: bool|null,
     * } $data
     */
    public function handle(array $data): PurchaseReturn
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
    private function persist(array $data, ?string $clientRequestId): PurchaseReturn
    {
        $items = $this->normalizeItems($data['items'], $data['purchase_order_id']);
        $subtotal = $this->computeSubtotal($items);
        $discount = new Money((int) ($data['discount_minor'] ?? 0));
        $total = $subtotal->subtract($discount);

        if ($total->minor() <= 0) {
            throw new \InvalidArgumentException('Total retur harus lebih dari 0.');
        }

        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        /** @var PurchaseOrder|null $po */
        $po = PurchaseOrder::query()
            ->whereKey((int) $data['purchase_order_id'])
            ->lockForUpdate()
            ->first();

        if (! $po || $po->status === 'cancelled') {
            throw new PurchaseOrderClosed('PO tidak ditemukan atau sudah dibatalkan.');
        }

        // Validate that return quantities don't exceed received quantities
        $receivedMap = $po->items->keyBy('product_id');
        foreach ($items as $item) {
            $poItem = $receivedMap->get($item['product_id']);
            if (! $poItem) {
                throw new InvalidReturnQuantity("Produk {$item['product_id']} tidak ada di PO ini.");
            }
            if ($item['qty'] > $poItem->received_quantity) {
                throw new InvalidReturnQuantity(
                    "Qty retur melebihi qty yang sudah diterima (maks: {$poItem->received_quantity})."
                );
            }
        }

        $purchaseReturn = PurchaseReturn::create([
            'purchase_order_id' => $po->id,
            'supplier_id' => $data['supplier_id'],
            'reason' => $data['reason'] ?? null,
            'subtotal_minor' => $subtotal->minor(),
            'discount_minor' => $discount->minor(),
            'total_minor' => $total->minor(),
            'user_id' => $data['user_id'],
            'location_id' => $data['location_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'client_request_id' => $clientRequestId,
            'tenant_id' => $tenantId,
        ]);

        foreach ($items as $item) {
            $poItem = $receivedMap->get($item['product_id']);
            $unitPrice = new Money($item['price_minor']);
            $lineSubtotal = $unitPrice->multiply($item['qty']);
            $lineDiscount = new Money($item['discount_minor'] ?? 0);

            PurchaseReturnItem::create([
                'purchase_return_id' => $purchaseReturn->id,
                'product_id' => $item['product_id'],
                'product_unit_id' => $item['product_unit_id'],
                'qty' => $item['qty'],
                'price_minor' => $unitPrice->minor(),
                'discount_minor' => $lineDiscount->minor(),
                'subtotal_minor' => $lineSubtotal->subtract($lineDiscount)->minor(),
                'reason' => $item['reason'] ?? null,
                'tenant_id' => $tenantId,
            ]);

            // Decrement stock
            $product = Product::query()->find($item['product_id']);
            if ($product && ! $product->is_unlimited_stock) {
                $conversionFactor = $poItem?->productUnit?->conversion_factor ?? 1;
                $baseQty = $item['qty'] * $conversionFactor;
                $before = $product->stock;
                $product->decrement('stock', $baseQty);

                StockMovement::create([
                    'product_id' => $product->id,
                    'quantity_change' => -$baseQty,
                    'before_stock' => $before,
                    'after_stock' => $product->stock,
                    'reference_type' => 'purchase_return',
                    'reference_id' => $purchaseReturn->id,
                    'user_id' => $data['user_id'],
                    'notes' => 'Purchase return '.$purchaseReturn->return_number,
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        // Update supplier balance (reduce money owed)
        $supplier = Supplier::query()->find($data['supplier_id']);
        if ($supplier) {
            $supplier->decrement('balance_minor', $total->minor());
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'],
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::PurchaseReturnCreated->value,
            'entity_type' => 'purchase_return',
            'entity_id' => $purchaseReturn->uuid,
            'metadata' => [
                'client_request_id' => $clientRequestId,
                'return_number' => $purchaseReturn->return_number,
                'purchase_order_id' => $po->uuid,
                'supplier_id' => $data['supplier_id'],
                'total_minor' => $total->minor(),
            ],
        ]);

        $this->enqueueForSync($data, $purchaseReturn, $items, $tenantId, $clientRequestId);

        return $purchaseReturn->load('items');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    private function enqueueForSync(
        array $data,
        PurchaseReturn $pr,
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
            'purchase_return',
            $pr->uuid,
            SyncOperationType::Create,
            [
                'purchase_order_id' => $data['purchase_order_id'],
                'supplier_id' => $data['supplier_id'],
                'reason' => $data['reason'] ?? null,
                'discount_minor' => $data['discount_minor'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'items' => array_map(static fn (array $item): array => [
                    'product_id' => $item['product_id'],
                    'product_unit_id' => $item['product_unit_id'],
                    'qty' => $item['qty'],
                    'price_minor' => $item['price_minor'],
                    'discount_minor' => $item['discount_minor'] ?? 0,
                    'reason' => $item['reason'] ?? null,
                ], $items),
            ],
            $clientRequestId,
        );
    }

    /**
     * @return list<array{product_id: int, product_unit_id: int|null, qty: int, price_minor: int, discount_minor: int, reason: string|null}>
     */
    private function normalizeItems(mixed $rawItems, int $poId): array
    {
        if (! is_array($rawItems) || count($rawItems) === 0) {
            throw new \InvalidArgumentException('Items retur tidak boleh kosong.');
        }

        $normalized = [];

        foreach ($rawItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);
            $priceMinor = (int) ($item['price_minor'] ?? -1);
            $discountMinor = (int) ($item['discount_minor'] ?? 0);

            if ($productId <= 0 || $qty <= 0 || $priceMinor < 0 || $discountMinor < 0) {
                throw new \InvalidArgumentException('Data item retur tidak valid.');
            }

            $normalized[] = [
                'product_id' => $productId,
                'product_unit_id' => isset($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
                'qty' => $qty,
                'price_minor' => $priceMinor,
                'discount_minor' => $discountMinor,
                'reason' => $item['reason'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{qty: int, price_minor: int, discount_minor: int}>  $items
     */
    private function computeSubtotal(array $items): Money
    {
        $subtotal = Money::zero();

        foreach ($items as $item) {
            $subtotal = $subtotal->add(
                (new Money($item['price_minor']))->multiply($item['qty']),
            );
        }

        return $subtotal;
    }

    private function findByIdempotencyKey(string $clientRequestId, ?string $tenantId = null): ?PurchaseReturn
    {
        return PurchaseReturn::query()
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
