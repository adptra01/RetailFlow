<?php

namespace App\Actions\Purchases;

use App\Enums\AuditAction;
use App\Enums\SyncOperationType;
use App\Exceptions\Purchases\InvalidPaymentAmount;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Domain entry point for recording a supplier payment.
 *
 * Guarantees:
 *  - atomicity: the payment record, supplier balance update and audit commit together;
 *  - idempotency: replaying the same client_request_id returns the original payment;
 *  - honesty: amount must be positive and never exceed the supplier's outstanding balance;
 *  - traceability: audit log entry is created.
 */
class RecordSupplierPayment
{
    /**
     * @param  array{
     *     supplier_id: int,
     *     purchase_order_id?: int|null,
     *     amount_minor: int,
     *     payment_method?: string,
     *     notes?: string|null,
     *     user_id?: int|null,
     *     device_id?: string|null,
     *     client_request_id?: string|null,
     *     paid_at?: Carbon|null,
     *     tenant_id?: string|null,
     *     location_id?: string|null,
     *     enqueue_for_sync?: bool|null,
     * } $data
     */
    public function handle(array $data): SupplierPayment
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
    private function persist(array $data, ?string $clientRequestId): SupplierPayment
    {
        $amount = new Money((int) ($data['amount_minor'] ?? 0));

        if ($amount->minor() <= 0) {
            throw new InvalidPaymentAmount('Jumlah pembayaran harus lebih dari 0.');
        }

        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        $supplier = Supplier::query()
            ->whereKey((int) $data['supplier_id'])
            ->lockForUpdate()
            ->first();

        if (! $supplier) {
            throw new \InvalidArgumentException('Supplier tidak ditemukan.');
        }

        // Check if payment exceeds outstanding balance
        $outstanding = $supplier->balance_minor;
        if ($amount->minor() > $outstanding) {
            throw new InvalidPaymentAmount('Pembayaran melebihi hutang supplier.');
        }

        $payment = SupplierPayment::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'amount_minor' => $amount->minor(),
            'payment_date' => ($data['paid_at'] ?? now())->toDateString(),
            'payment_method' => $data['payment_method'] ?? 'transfer',
            'notes' => $data['notes'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'client_request_id' => $clientRequestId,
            'tenant_id' => $tenantId,
        ]);

        $supplier->decrement('balance_minor', $amount->minor());

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::SupplierPaymentRecorded->value,
            'entity_type' => 'supplier_payment',
            'entity_id' => $payment->uuid,
            'metadata' => [
                'supplier_id' => $supplier->uuid,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'amount_minor' => $payment->amount_minor,
                'payment_method' => $payment->payment_method,
                'new_balance_minor' => $supplier->fresh()->balance_minor,
                'client_request_id' => $clientRequestId,
            ],
        ]);

        $this->enqueueForSync($data, $payment, $supplier, $tenantId, $clientRequestId);

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function enqueueForSync(
        array $data,
        SupplierPayment $payment,
        Supplier $supplier,
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
            'supplier_payment',
            $payment->uuid,
            SyncOperationType::Create,
            [
                'supplier_id' => $supplier->uuid,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'amount_minor' => $payment->amount_minor,
                'payment_date' => $payment->payment_date?->format('Y-m-d'),
                'payment_method' => $payment->payment_method,
                'notes' => $payment->notes,
                'location_id' => $data['location_id'] ?? null,
            ],
            $clientRequestId,
        );
    }

    private function findByIdempotencyKey(string $clientRequestId, ?string $tenantId = null): ?SupplierPayment
    {
        return SupplierPayment::query()
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
