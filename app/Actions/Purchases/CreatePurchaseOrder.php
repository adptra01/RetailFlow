<?php

namespace App\Actions\Purchases;

use App\Enums\AuditAction;
use App\Enums\SyncOperationType;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Domain entry point for creating a purchase order.
 *
 * Guarantees:
 *  - atomicity: the PO and its items commit or roll back together;
 *  - idempotency: replaying the same client_request_id returns the original PO;
 *  - traceability: audit log entry is created.
 */
class CreatePurchaseOrder
{
    /**
     * @param  array{
     *     supplier_id: int,
     *     user_id: int,
     *     items: list<array{
     *         product_id: int,
     *         product_unit_id?: int|null,
     *         quantity: int,
     *         unit_price_minor: int,
     *         discount_minor?: int|null,
     *     }>,
     *     notes?: string|null,
     *     client_request_id?: string|null,
     *     tenant_id?: string|null,
     *     location_id?: string|null,
     *     device_id?: string|null,
     *     enqueue_for_sync?: bool|null,
     * } $data
     */
    public function handle(array $data): PurchaseOrder
    {
        $clientRequestId = $data['client_request_id'] ?? null;

        if (is_string($clientRequestId) && $clientRequestId !== '') {
            $existing = $this->findByIdempotencyKey($clientRequestId);
            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(fn () => $this->persist($data, $clientRequestId));
        } catch (QueryException $e) {
            if ($this->isIdempotencyViolation($e) && is_string($clientRequestId) && $clientRequestId !== '') {
                return $this->findByIdempotencyKey($clientRequestId) ?? throw $e;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, ?string $clientRequestId): PurchaseOrder
    {
        $items = $this->normalizeItems($data['items']);
        $subtotal = $this->computeSubtotal($items);
        $discount = new Money((int) ($data['discount_minor'] ?? 0));
        $total = $subtotal->subtract($discount);

        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $data['supplier_id'],
            'user_id' => $data['user_id'],
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'client_request_id' => $clientRequestId,
            'subtotal_minor' => $subtotal->minor(),
            'discount_minor' => $discount->minor(),
            'total_minor' => $total->minor(),
            'tenant_id' => $tenantId,
            'location_id' => $data['location_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
        ]);

        foreach ($items as $item) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $purchaseOrder->id,
                'product_id' => $item['product_id'],
                'product_unit_id' => $item['product_unit_id'],
                'quantity' => $item['quantity'],
                'unit_price_minor' => $item['unit_price_minor'],
                'discount_minor' => $item['discount_minor'] ?? 0,
                'subtotal_minor' => (new Money($item['unit_price_minor']))->multiply($item['quantity'])->subtract(new Money($item['discount_minor'] ?? 0))->minor(),
                'received_quantity' => 0,
                'tenant_id' => $tenantId,
            ]);
        }

        // Update supplier balance (money owed to supplier)
        $supplier = Supplier::query()->find($data['supplier_id']);
        if ($supplier) {
            $supplier->increment('balance_minor', $total->minor());
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'],
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::PurchaseOrderCreated->value,
            'entity_type' => 'purchase_order',
            'entity_id' => $purchaseOrder->uuid,
            'metadata' => [
                'client_request_id' => $clientRequestId,
                'order_number' => $purchaseOrder->order_number,
                'supplier_id' => $data['supplier_id'],
                'total_minor' => $total->minor(),
            ],
        ]);

        $this->enqueueForSync($data, $purchaseOrder, $items, $tenantId, $clientRequestId);

        return $purchaseOrder->load('items');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    private function enqueueForSync(
        array $data,
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
            'purchase_order',
            $po->uuid,
            SyncOperationType::Create,
            $this->syncPayload($data, $items),
            $clientRequestId,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function syncPayload(array $data, array $items): array
    {
        return [
            'supplier_id' => $data['supplier_id'],
            'user_id' => $data['user_id'],
            'notes' => $data['notes'] ?? null,
            'discount_minor' => $data['discount_minor'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'items' => array_map(static fn (array $item): array => [
                'product_id' => $item['product_id'],
                'product_unit_id' => $item['product_unit_id'],
                'quantity' => $item['quantity'],
                'unit_price_minor' => $item['unit_price_minor'],
                'discount_minor' => $item['discount_minor'] ?? 0,
            ], $items),
        ];
    }

    /**
     * @return list<array{product_id: int, product_unit_id: int|null, quantity: int, unit_price_minor: int, discount_minor: int}>
     */
    private function normalizeItems(mixed $rawItems): array
    {
        if (! is_array($rawItems) || count($rawItems) === 0) {
            throw new \InvalidArgumentException('Items tidak boleh kosong.');
        }

        $normalized = [];

        foreach ($rawItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitPriceMinor = (int) ($item['unit_price_minor'] ?? -1);
            $discountMinor = (int) ($item['discount_minor'] ?? 0);

            if ($productId <= 0 || $quantity <= 0 || $unitPriceMinor < 0 || $discountMinor < 0) {
                throw new \InvalidArgumentException('Data item PO tidak valid.');
            }

            $normalized[] = [
                'product_id' => $productId,
                'product_unit_id' => isset($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
                'quantity' => $quantity,
                'unit_price_minor' => $unitPriceMinor,
                'discount_minor' => $discountMinor,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{quantity: int, unit_price_minor: int, discount_minor: int}>  $items
     */
    private function computeSubtotal(array $items): Money
    {
        $subtotal = Money::zero();

        foreach ($items as $item) {
            $subtotal = $subtotal->add(
                (new Money($item['unit_price_minor']))->multiply($item['quantity']),
            );
        }

        return $subtotal;
    }

    private function findByIdempotencyKey(string $clientRequestId): ?PurchaseOrder
    {
        return PurchaseOrder::query()
            ->withoutGlobalScopes()
            ->where('client_request_id', $clientRequestId)
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
