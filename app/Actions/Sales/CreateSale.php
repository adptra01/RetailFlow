<?php

namespace App\Actions\Sales;

use App\Exceptions\Sales\InsufficientStock;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single domain entry point for creating a sale (plan §24 Fase 2).
 *
 * Guarantees, enforced here and nowhere else:
 *  - atomicity: the whole sale (order, items, stock, ledger, audit) commits
 *    or rolls back together (§17.3);
 *  - idempotency: a replay with the same client_request_id returns the
 *    original sale instead of creating a duplicate;
 *  - stock safety: quantity is validated against locked rows, converted into
 *    base stock units via the unit conversion factor, and never decremented
 *    past zero (FR-08);
 *  - discount honesty (FR-06): snapshots are taken on the line items so later
 *    price/discount changes never alter historical sales;
 *  - receivable basics (FR-07): when payment is partial the sale becomes a
 *    credit sale — a customer is required and only the unpaid balance moves
 *    into the customer balance;
 *  - traceability: every unit of stock leaving the shelf is recorded in the
 *    ledger with reference_type "sale" (FR-08);
 *  - honest money: all amounts are integer minor units (FR-04 / §17.1).
 *
 * Money math (IDR minor exponent 0, see config/pos.php):
 *  - item subtotal      = unit_price_minor * quantity
 *  - item discount      = discount_minor (per line, default 0)
 *  - transaction total  = subtotal - discount_minor (nominal wins over percent)
 *  - paid > total       = cash only, change returned; non-cash overpay rejected
 *  - paid == total      = paid in full
 *  - paid < total       = credit sale (customer required), balance += unpaid
 */
class CreateSale
{
    /**
     * @param  array{
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         unit_price_minor: int,
     *         product_unit_id?: int|null,
     *         unit_name?: string|null,
     *         conversion_factor?: float|int|null,
     *         discount_minor?: int|null,
     *     }>,
     *     payment_method?: string,
     *     paid_minor?: int,
     *     discount_minor?: int|null,
     *     discount_percent?: int|float|null,
     *     client_request_id?: string|null,
     *     user_id: int,
     *     customer_id?: int|null,
     *     customer_name?: string|null,
     *     location_id?: string|null,
     *     device_id?: string|null,
     *     notes?: string|null,
     *     tenant_id?: string|null,
     * } $data
     */
    public function handle(array $data): Transaction
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
            // Concurrent duplicate submission: the unique constraint fires,
            // so the winner's sale is returned.
            if ($this->isIdempotencyViolation($e) && is_string($clientRequestId) && $clientRequestId !== '') {
                return $this->findByIdempotencyKey($clientRequestId) ?? throw $e;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, ?string $clientRequestId): Transaction
    {
        $items = $this->normalizeItems($data['items']);
        $subtotal = $this->computeSubtotal($items);
        $discount = $this->resolveTransactionDiscount($data, $subtotal);
        $total = $subtotal->subtract($discount);
        $paid = new Money((int) ($data['paid_minor'] ?? 0));
        $paymentMethod = (string) ($data['payment_method'] ?? 'cash');
        $change = $this->resolveChange($paymentMethod, $paid, $total);

        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        /** @var array<int, Product> $products */
        $products = Product::query()
            ->whereKey(array_column($items, 'product_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                throw new \InvalidArgumentException("Produk #{$item['product_id']} tidak ditemukan.");
            }

            $this->assertStock($product, $this->baseQuantity($item));
        }

        $customer = isset($data['customer_id'])
            ? Customer::query()->find($data['customer_id'])
            : null;

        $isCredit = $paid->minor() < $total->minor();

        // FR-07: receivables only exist when there is an unpaid balance.
        if ($isCredit && ! $customer) {
            throw new InvalidPaymentAmount('Pilih pelanggan untuk penjualan kredit.');
        }

        $transaction = Transaction::create([
            'user_id' => $data['user_id'],
            'customer_id' => $customer?->id,
            'customer_name' => $data['customer_name'] ?? $customer?->name ?? '',
            'invoice_number' => $this->generateInvoiceNumber(),
            'payment_method' => $paymentMethod,
            'payment_status' => $isCredit ? 'credit' : 'paid',
            'notes' => $data['notes'] ?? null,
            'client_request_id' => $clientRequestId,
            'subtotal_minor' => $subtotal->minor(),
            'discount_minor' => $discount->minor(),
            'total_minor' => $total->minor(),
            'paid_minor' => $paid->minor(),
            'change_minor' => $change->minor(),
            // Transitional decimal mirror so the pre-rebuild UI keeps working.
            'total_amount' => $total->major(),
            'paid_amount' => $paid->major(),
            'change_amount' => $change->major(),
            'tenant_id' => $tenantId,
            'location_id' => $data['location_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
        ]);

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $unitPrice = new Money($item['unit_price_minor']);
            $lineSubtotal = $unitPrice->multiply($item['quantity']);
            $lineDiscount = new Money($item['discount_minor']);

            TransactionItem::create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'product_unit_id' => $item['product_unit_id'],
                'unit_name' => $item['unit_name'],
                'quantity' => $item['quantity'],
                'unit_price_minor' => $unitPrice->minor(),
                'discount_minor' => $lineDiscount->minor(),
                'subtotal_minor' => $lineSubtotal->minor(),
                // Transitional decimal mirror.
                'unit_price' => $unitPrice->major(),
                'subtotal' => $lineSubtotal->major(),
                'tenant_id' => $tenantId,
            ]);

            $this->decrementStock($product, $item, $transaction, $data['user_id'], $tenantId);
        }

        if ($isCredit && $customer) {
            $unpaid = $total->subtract($paid);

            // Transitional mirror: the customer balance is a derived cache and
            // is only ever touched by domain actions (FR-07).
            $customer->increment('balance', $unpaid->major());

            // §17.3 step 6: a receivable is born from the unpaid balance.
            Receivable::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'transaction_id' => $transaction->id,
                'original_amount_minor' => $unpaid->minor(),
                'remaining_minor' => $unpaid->minor(),
                'due_at' => null,
                'status' => 'open',
            ]);
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'],
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::SaleCreated->value,
            'entity_type' => 'sale',
            'entity_id' => $transaction->uuid,
            'metadata' => [
                'client_request_id' => $clientRequestId,
                'invoice_number' => $transaction->invoice_number,
                'payment_status' => $transaction->payment_status,
            ],
        ]);

        return $transaction->load('items');
    }

    /**
     * Normalizes and validates raw line items into domain-shaped records.
     *
     * @return list<array{product_id: int, quantity: int, unit_price_minor: int, discount_minor: int, conversion_factor: float, product_unit_id: int|null, unit_name: string|null}>
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
            $factor = (float) ($item['conversion_factor'] ?? 1);

            if ($productId <= 0 || $quantity <= 0 || $unitPriceMinor < 0 || $discountMinor < 0 || $factor <= 0) {
                throw new \InvalidArgumentException('Data item penjualan tidak valid.');
            }

            $normalized[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price_minor' => $unitPriceMinor,
                'discount_minor' => $discountMinor,
                'conversion_factor' => $factor,
                'product_unit_id' => isset($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
                'unit_name' => $item['unit_name'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{quantity: int, unit_price_minor: int, discount_minor: int, conversion_factor: float}>  $items
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

    /**
     * FR-06: nominal discount wins over percent when both are given.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTransactionDiscount(array $data, Money $subtotal): Money
    {
        $nominal = (int) ($data['discount_minor'] ?? 0);
        $percent = (float) ($data['discount_percent'] ?? 0);

        if ($nominal > 0) {
            return new Money($nominal);
        }

        if ($percent > 0) {
            $minor = (int) round($subtotal->minor() * $percent / 100);

            return new Money($minor);
        }

        return Money::zero();
    }

    /**
     * @param  array{quantity: int, conversion_factor: float}  $item
     * @return int stock units consumed (base unit of measure)
     */
    private function baseQuantity(array $item): int
    {
        return max(1, (int) round($item['quantity'] * $item['conversion_factor']));
    }

    private function resolveChange(string $paymentMethod, Money $paid, Money $total): Money
    {
        if ($paid->minor() > $total->minor() && $paymentMethod !== 'cash') {
            throw new InvalidPaymentAmount('Pembayaran non-tunai tidak boleh melebihi total.');
        }

        if ($paid->minor() < $total->minor()) {
            // Credit/partial sale: no change, unpaid portion tracked as balance.
            return Money::zero();
        }

        return $paid->subtract($total);
    }

    private function assertStock(Product $product, int $baseQuantity): void
    {
        if ($product->is_unlimited_stock) {
            return;
        }

        if ($product->stock < $baseQuantity) {
            throw new InsufficientStock($product->id, $product->stock, $baseQuantity);
        }
    }

    /**
     * @param  array{quantity: int, conversion_factor: float}  $item
     */
    private function decrementStock(Product $product, array $item, Transaction $transaction, int $userId, ?string $tenantId): void
    {
        $baseQty = $this->baseQuantity($item);
        $before = $product->stock;

        $product->stock = $before - $baseQty;
        // saveQuietly() on purpose: the ledger entry below is authoritative
        // and carries the sale reference. The observer only covers manual
        // adjustments, so using it here would duplicate movements (C5).
        $product->saveQuietly();

        StockMovement::create([
            'product_id' => $product->id,
            'quantity_change' => -$baseQty,
            'before_stock' => $before,
            'after_stock' => $product->stock,
            'reference_type' => 'sale',
            'reference_id' => $transaction->id,
            'user_id' => $userId,
            'notes' => 'Sale '.$transaction->invoice_number,
            'tenant_id' => $tenantId,
        ]);
    }

    private function generateInvoiceNumber(): string
    {
        $retries = 5;
        $exists = true;

        do {
            $number = 'INV-'.now()->format('ymdHis').'-'.strtoupper(Str::random(4));
            $exists = Transaction::query()->withoutGlobalScopes()->where('invoice_number', $number)->exists();
        } while ($exists && --$retries > 0);

        if ($exists) {
            throw new \RuntimeException('Gagal menghasilkan nomor invoice yang unik.');
        }

        return $number;
    }

    private function findByIdempotencyKey(string $clientRequestId): ?Transaction
    {
        return Transaction::query()
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
