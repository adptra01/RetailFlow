<?php

namespace App\Actions\Receivables;

use App\Enums\AuditAction;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Exceptions\Sales\ReceivableClosed;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Domain entry point for recording a receivable payment (FR-07).
 *
 * Guarantees, enforced here and nowhere else:
 *  - atomicity: the payment record, remaining-balance update, customer
 *    balance mirror and audit commit or roll back together;
 *  - idempotency: replaying the same client_request_id returns the original
 *    payment instead of double-charging;
 *  - honesty: the receivable is row-locked, amount must be positive and
 *    never exceed the remaining balance, and a closed receivable cannot be
 *    paid again (FR-07: "saldo piutang tidak boleh diubah dengan mengedit
 *    angka secara langsung" — corrections go through audited records);
 *  - traceability: every payment writes an audit log entry.
 */
class RecordReceivablePayment
{
    /**
     * @param  array{
     *     receivable_id: int,
     *     amount_minor: int,
     *     payment_method?: string,
     *     user_id?: int|null,
     *     device_id?: string|null,
     *     client_request_id?: string|null,
     *     notes?: string|null,
     *     paid_at?: Carbon|null,
     *     tenant_id?: string|null,
     * } $data
     */
    public function handle(array $data): ReceivablePayment
    {
        $clientRequestId = $data['client_request_id'] ?? null;

        // §12: kunci idempotensi dicari DI DALAM tenant pemanggil. Tanpa
        // pembatasan ini, kunci milik tenant lain dapat mengembalikan
        // pembayaran piutang tenant lain — sekaligus menolak pembayaran sah
        // kita sendiri.
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
    private function persist(array $data, ?string $clientRequestId): ReceivablePayment
    {
        $amount = new Money((int) ($data['amount_minor'] ?? 0));

        if ($amount->minor() <= 0) {
            throw new InvalidPaymentAmount('Jumlah pembayaran piutang harus lebih dari 0.');
        }

        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        /** @var Receivable|null $receivable */
        $receivable = Receivable::query()
            ->whereKey((int) $data['receivable_id'])
            ->lockForUpdate()
            ->first();

        if (! $receivable || $receivable->status !== 'open') {
            throw new ReceivableClosed('Piutang tidak ditemukan atau sudah lunas.');
        }

        if ($amount->minor() > $receivable->remaining_minor) {
            throw new InvalidPaymentAmount('Pembayaran melebihi sisa piutang.');
        }

        $payment = ReceivablePayment::create([
            'tenant_id' => $tenantId,
            'receivable_id' => $receivable->id,
            'payment_id' => $data['payment_id'] ?? null,
            'amount_minor' => $amount->minor(),
            'payment_method' => $data['payment_method'] ?? 'cash',
            'recorded_by' => $data['user_id'] ?? null,
            'client_request_id' => $clientRequestId,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $data['paid_at'] ?? now(),
        ]);

        $receivable->remaining_minor = max(0, $receivable->remaining_minor - $amount->minor());

        if ($receivable->remaining_minor === 0) {
            $receivable->status = 'closed';
        }

        $receivable->save();

        // Transitional mirror: the customer balance is a derived cache and is
        // only ever touched by domain actions (FR-07). A future increment
        // derives it from receivable sums instead.
        if ($receivable->customer_id) {
            $customer = Customer::query()->find($receivable->customer_id);

            if ($customer) {
                $current = (float) $customer->balance;
                $customer->update(['balance' => max(0, $current - $amount->major())]);
            }
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::ReceivablePayment->value,
            'entity_type' => 'receivable_payment',
            'entity_id' => $payment->uuid,
            'metadata' => [
                'receivable_id' => $receivable->uuid,
                'transaction_id' => $receivable->transaction_id,
                'amount_minor' => $payment->amount_minor,
                'remaining_minor' => $receivable->remaining_minor,
                'payment_status' => $receivable->status,
                'client_request_id' => $clientRequestId,
            ],
        ]);

        return $payment;
    }

    private function findByIdempotencyKey(string $clientRequestId, ?string $tenantId = null): ?ReceivablePayment
    {
        return ReceivablePayment::query()
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
