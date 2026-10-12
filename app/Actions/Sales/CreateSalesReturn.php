<?php

namespace App\Actions\Sales;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SyncOperationType;
use App\Exceptions\Sales\ReturnNotAllowed;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Retur penjualan terkontrol (FR-08 "Retur penjualan dengan alasan", FR-09
 * "Retur dan pembatalan", §17.4).
 *
 * Kebijakan yang ditegakkan:
 *  - **alasan wajib** dan **izin wajib** (`transaction.return`, FR-12);
 *  - **tidak melebihi yang dijual**: jumlah retur per baris dibatasi sisa yang
 *    belum pernah diretur, sehingga retur berulang tidak dapat melebihi nota;
 *  - **transaksi batal tidak dapat diretur** (koreksinya sudah void);
 *  - **stok hanya dikembalikan sesuai kebijakan** (`pos.sales.restore_stock_on_return`),
 *    lewat mutasi `sale_return` agar setiap perubahan stok dapat ditelusuri;
 *  - **koreksi pembayaran tercatat** (§17.4): nilai retur lebih dahulu
 *    membatalkan sisa piutang (`receivable_adjustment_minor`), sisanya
 *    dikembalikan sebagai kas (`refund_minor`);
 *  - **idempoten**: `client_request_id` yang sama mengembalikan dokumen retur
 *    yang sama, sehingga pengiriman ulang dari perangkat tidak menggandakan
 *    pengembalian stok;
 *  - **jejak audit** `sale.returned` (FR-12) dan operasi outbox perangkat bila
 *    retur dilakukan di perangkat (§11.1).
 */
class CreateSalesReturn
{
    /**
     * @param  array{
     *     transaction_id?: int|null,
     *     uuid?: string|null,
     *     user_id: int,
     *     reason: string,
     *     items: list<array{
     *         transaction_item_id: int,
     *         quantity: int,
     *         reason?: string|null,
     *     }>,
     *     restore_stock?: bool|null,
     *     client_request_id?: string|null,
     *     tenant_id?: string|null,
     *     location_id?: string|null,
     *     device_id?: string|null,
     *     enqueue_for_sync?: bool,
     * }  $data
     */
    public function handle(array $data, ?User $actor = null): SalesReturn
    {
        ActionAuthorizer::assertIf($actor, Permission::ReturnTransaction);

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw ReturnNotAllowed::reasonRequired();
        }

        $items = $this->normalizeItems($data['items'] ?? []);

        if ($items === []) {
            throw ReturnNotAllowed::noItems();
        }

        $clientRequestId = $data['client_request_id'] ?? null;

        if (is_string($clientRequestId) && $clientRequestId !== '') {
            $existing = $this->findByIdempotencyKey($clientRequestId);

            if ($existing !== null) {
                return $existing->load('items');
            }
        }

        try {
            return DB::transaction(fn () => $this->persist($data, $items, $reason, $clientRequestId));
        } catch (QueryException $e) {
            if ($this->isIdempotencyViolation($e) && is_string($clientRequestId) && $clientRequestId !== '') {
                return ($this->findByIdempotencyKey($clientRequestId) ?? throw $e)->load('items');
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{transaction_item_id: int, quantity: int, reason: string|null}>  $items
     */
    private function persist(array $data, array $items, string $reason, ?string $clientRequestId): SalesReturn
    {
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        $transaction = $this->lockTransaction($data);

        if ($transaction->isVoided()) {
            throw ReturnNotAllowed::voidedSale((string) $transaction->invoice_number);
        }

        $soldItems = TransactionItem::query()
            ->withoutGlobalScopes()
            ->where('transaction_id', $transaction->id)
            ->get()
            ->keyBy('id');

        $alreadyReturned = $this->returnedQuantities($transaction->id);
        $restorePolicy = $data['restore_stock'] ?? config('pos.sales.restore_stock_on_return', true);
        $restoreStock = (bool) $restorePolicy;

        $lines = [];
        $total = Money::zero();

        foreach ($items as $item) {
            /** @var TransactionItem|null $sold */
            $sold = $soldItems->get($item['transaction_item_id']);

            if ($sold === null) {
                throw ReturnNotAllowed::itemNotInSale($item['transaction_item_id']);
            }

            $remaining = (int) $sold->quantity - ($alreadyReturned[$sold->id] ?? 0);

            if ($item['quantity'] > $remaining) {
                throw ReturnNotAllowed::exceedsSoldQuantity(
                    (string) ($sold->product?->name ?? 'produk #'.$sold->product_id),
                    max(0, $remaining),
                );
            }

            // Nilai retur mengikuti apa yang benar-benar dibayar pelanggan
            // untuk baris itu (setelah diskon baris), bukan harga katalog kini.
            $lineValue = new Money($this->proportionalValue((int) $sold->subtotal_minor, (int) $sold->quantity, $item['quantity']));
            $total = $total->add($lineValue);

            $lines[] = [
                'sold' => $sold,
                'quantity' => $item['quantity'],
                'reason' => $item['reason'] ?? null,
                'value' => $lineValue,
                'unit_value' => new Money($this->proportionalValue((int) $sold->subtotal_minor, (int) $sold->quantity, 1)),
            ];
        }

        [$receivableAdjustment, $refund] = $this->settlePaymentCorrection(
            $transaction,
            $total,
            (int) $data['user_id'],
            $tenantId,
        );

        $salesReturn = SalesReturn::create([
            'transaction_id' => $transaction->id,
            'customer_id' => $transaction->customer_id,
            'reason' => $reason,
            'subtotal_minor' => $total->minor(),
            'discount_minor' => 0,
            'total_minor' => $total->minor(),
            'receivable_adjustment_minor' => $receivableAdjustment,
            'refund_minor' => $refund,
            'stock_restored' => $restoreStock,
            'total' => $total->major(),
            'user_id' => $data['user_id'],
            'location_id' => $data['location_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'client_request_id' => $clientRequestId,
            'tenant_id' => $tenantId,
        ]);

        foreach ($lines as $line) {
            /** @var TransactionItem $sold */
            $sold = $line['sold'];
            /** @var Money $value */
            $value = $line['value'];

            SalesReturnItem::create([
                'sales_return_id' => $salesReturn->id,
                'transaction_item_id' => $sold->id,
                'product_id' => $sold->product_id,
                'product_unit_id' => $sold->product_unit_id,
                'qty' => $line['quantity'],
                'unit_price_minor' => $line['unit_value']->minor(),
                'discount_minor' => 0,
                'subtotal_minor' => $value->minor(),
                'price' => $line['unit_value']->major(),
                'subtotal' => $value->major(),
                'reason' => $line['reason'],
                'tenant_id' => $tenantId,
            ]);

            if ($restoreStock) {
                $this->restoreStock(
                    $sold,
                    (int) $line['quantity'],
                    $salesReturn,
                    (int) $data['user_id'],
                    $tenantId,
                );
            }
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'],
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::SaleReturned->value,
            'entity_type' => 'sale_return',
            'entity_id' => $salesReturn->uuid,
            'metadata' => [
                'client_request_id' => $clientRequestId,
                'return_number' => $salesReturn->return_number,
                'invoice_number' => $transaction->invoice_number,
                'transaction_id' => $transaction->uuid,
                'reason' => $reason,
                'total_minor' => $total->minor(),
                'receivable_adjustment_minor' => $receivableAdjustment,
                'refund_minor' => $refund,
                'stock_restored' => $restoreStock,
                'items' => array_map(static fn (array $line): array => [
                    'transaction_item_id' => $line['sold']->id,
                    'quantity' => $line['quantity'],
                    'subtotal_minor' => $line['value']->minor(),
                ], $lines),
            ],
        ]);

        $this->enqueueForSync($data, $salesReturn, $transaction, $lines, $tenantId, $clientRequestId);

        return $salesReturn->load('items');
    }

    /**
     * Koreksi pembayaran (§17.4, FR-07).
     *
     * Nilai retur lebih dahulu membatalkan sisa piutang penjualan kredit —
     * pelanggan tidak lagi berutang atas barang yang dikembalikan — dan sisa
     * setelahnya menjadi uang yang benar-benar dikembalikan ke pelanggan.
     *
     * @return array{0: int, 1: int} [koreksi piutang minor, refund minor]
     */
    private function settlePaymentCorrection(Transaction $transaction, Money $total, int $userId, ?string $tenantId): array
    {
        $adjustment = 0;

        /** @var Receivable|null $receivable */
        $receivable = Receivable::query()
            ->withoutGlobalScopes()
            ->where('transaction_id', $transaction->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if ($receivable !== null) {
            $adjustment = min($total->minor(), (int) $receivable->remaining_minor);

            if ($adjustment > 0) {
                // `original_amount_minor` ikut dikurangi agar invarian
                // original = dibayar + sisa tetap berlaku (FR-07).
                $receivable->remaining_minor = (int) $receivable->remaining_minor - $adjustment;
                $receivable->original_amount_minor = max(0, (int) $receivable->original_amount_minor - $adjustment);

                if ($receivable->remaining_minor === 0) {
                    $receivable->status = 'closed';
                }

                $receivable->save();

                // Cermin saldo pelanggan (derived cache), seperti pada
                // RecordReceivablePayment.
                $customer = Customer::query()->find($receivable->customer_id);

                if ($customer !== null) {
                    $current = (float) $customer->balance;
                    $customer->update([
                        'balance' => max(0, $current - (new Money($adjustment))->major()),
                    ]);
                }
            }
        }

        return [$adjustment, $total->minor() - $adjustment];
    }

    /**
     * Kembalikan stok sebesar yang dijual, memakai faktor konversi satuan yang
     * dipakai saat penjualan. Ledger `sale_return` yang menjadi rujukan, bukan
     * penulisan langsung saldo (FR-08: setiap perubahan stok harus dapat
     * ditelusuri).
     */
    private function restoreStock(
        TransactionItem $sold,
        int $quantity,
        SalesReturn $salesReturn,
        int $userId,
        ?string $tenantId,
    ): void {
        /** @var Product|null $product */
        $product = Product::query()->lockForUpdate()->find($sold->product_id);

        if ($product === null || $product->is_unlimited_stock) {
            return;
        }

        $conversionFactor = (float) ($sold->productUnit?->conversion_factor ?? 1);
        $baseQty = (int) round($quantity * max(1.0, $conversionFactor));
        $before = (int) $product->stock;

        $product->stock = $before + $baseQty;
        // saveQuietly(): ledger di bawah adalah rujukan, agar observer tidak
        // menggandakan mutasi (pola CreateSale).
        $product->saveQuietly();

        StockMovement::create([
            'product_id' => $product->id,
            'quantity_change' => $baseQty,
            'before_stock' => $before,
            'after_stock' => $product->stock,
            'reference_type' => 'sale_return',
            'reference_id' => $salesReturn->id,
            'user_id' => $userId,
            'notes' => 'Retur '.$salesReturn->return_number,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Jumlah yang sudah pernah diretur per baris penjualan (retur berulang
     * tidak boleh melebihi nota).
     *
     * @return array<int, int> transaction_item_id => qty
     */
    private function returnedQuantities(int $transactionId): array
    {
        return SalesReturnItem::query()
            ->withoutGlobalScopes()
            ->whereHas('salesReturn', fn ($query) => $query->withoutGlobalScopes()->where('transaction_id', $transactionId))
            ->selectRaw('transaction_item_id, SUM(qty) as returned_qty')
            ->groupBy('transaction_item_id')
            ->pluck('returned_qty', 'transaction_item_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    /**
     * Nilai proporsional dari subtotal baris: `subtotal * part / whole`,
     * dibulatkan ke unit minor terdekat.
     */
    private function proportionalValue(int $subtotalMinor, int $whole, int $part): int
    {
        if ($whole <= 0 || $part <= 0) {
            return 0;
        }

        return (int) round($subtotalMinor * $part / $whole);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lockTransaction(array $data): Transaction
    {
        $query = Transaction::query()->lockForUpdate();

        if (! empty($data['transaction_id'])) {
            $query->whereKey($data['transaction_id']);
        } elseif (! empty($data['uuid'])) {
            $query->where('uuid', $data['uuid']);
        } else {
            throw ReturnNotAllowed::notFound();
        }

        return $query->first() ?? throw ReturnNotAllowed::notFound();
    }

    /**
     * @return list<array{transaction_item_id: int, quantity: int, reason: string|null}>
     */
    private function normalizeItems(mixed $rawItems): array
    {
        if (! is_array($rawItems)) {
            return [];
        }

        $normalized = [];

        foreach ($rawItems as $item) {
            $itemId = (int) ($item['transaction_item_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($itemId <= 0 || $quantity <= 0) {
                throw ReturnNotAllowed::invalidQuantity();
            }

            $normalized[] = [
                'transaction_item_id' => $itemId,
                'quantity' => $quantity,
                'reason' => $item['reason'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * §11.1/§11.3: retur dari perangkat ikut masuk outbox perangkat agar
     * terkirim ke server bila perangkat kembali online. Dipanggil DI DALAM
     * transaksi domain.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{sold: TransactionItem, quantity: int, reason: string|null, value: Money, unit_value: Money}>  $lines
     */
    private function enqueueForSync(
        array $data,
        SalesReturn $salesReturn,
        Transaction $transaction,
        array $lines,
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
            'sale_return',
            $salesReturn->uuid,
            SyncOperationType::Create,
            [
                'transaction_uuid' => $transaction->uuid,
                'reason' => $data['reason'] ?? null,
                'restore_stock' => $salesReturn->stock_restored,
                'items' => array_map(static fn (array $line): array => [
                    'transaction_item_id' => $line['sold']->id,
                    'quantity' => $line['quantity'],
                ], $lines),
            ],
            $clientRequestId,
        );
    }

    private function findByIdempotencyKey(string $clientRequestId): ?SalesReturn
    {
        return SalesReturn::query()
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
