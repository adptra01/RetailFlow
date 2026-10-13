<?php

namespace App\Actions\Receivables;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\Receivables\PaymentReversalNotAllowed;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi/pembatalan pembayaran piutang — prosedur yang diaudit (FR-07).
 *
 * FR-07 menuntut: "Saldo piutang tidak boleh diubah dengan mengedit angka
 * secara langsung" dan "Koreksi harus dilakukan melalui transaksi koreksi yang
 * memiliki alasan dan jejak audit." Aksi ini satu-satunya jalan mengoreksi:
 *  - **append-only**: baris pembayaran TIDAK dihapus, hanya ditandai
 *    `reversed_at` + `reversal_reason` + `reversed_by`;
 *  - **alasan wajib**: koreksi tanpa alasan ditolak;
 *  - **izin wajib**: bila aktor diberikan, izin `receivable.payment.correct`
 *    diperiksa lewat `ActionAuthorizer` (FR-12);
 *  - **idempoten**: pembayaran yang sudah dikoreksi dikembalikan apa adanya,
 *    tanpa menambah saldo dua kali;
 *  - **konsisten & atomik**: `remaining_minor` dikembalikan, status piutang
 *    dibuka lagi bila sebelumnya tertutup, dan cermin saldo pelanggan
 *    disesuaikan — semuanya dalam satu transaksi;
 *  - **jejak audit**: satu baris `receivable.payment.corrected` dengan aktor,
 *    perangkat, alasan, dan ringkasan perubahan.
 */
class ReverseReceivablePayment
{
    /**
     * @param  array{
     *     payment_id?: int,
     *     uuid?: string|null,
     *     user_id?: int|null,
     *     reason: string,
     *     tenant_id?: string|null,
     *     device_id?: string|null,
     * }  $data
     */
    public function handle(array $data, ?User $actor = null): ReceivablePayment
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw PaymentReversalNotAllowed::reasonRequired();
        }

        ActionAuthorizer::assertIf($actor, Permission::CorrectReceivablePayment);

        return DB::transaction(fn () => $this->persist($data, $reason));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, string $reason): ReceivablePayment
    {
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        $query = ReceivablePayment::query()->withoutGlobalScopes();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if (isset($data['payment_id'])) {
            $query->whereKey((int) $data['payment_id']);
        } elseif (! empty($data['uuid'])) {
            $query->where('uuid', (string) $data['uuid']);
        }

        /** @var ReceivablePayment|null $payment */
        $payment = $query->lockForUpdate()->first();

        if ($payment === null) {
            throw PaymentReversalNotAllowed::notFound();
        }

        // Idempoten: koreksi atas pembayaran yang sudah dikoreksi tidak
        // menambah saldo lagi dan tidak menulis audit kedua.
        if ($payment->isReversed()) {
            return $payment;
        }

        /** @var Receivable|null $receivable */
        $receivable = Receivable::query()
            ->withoutGlobalScopes()
            ->whereKey($payment->receivable_id)
            ->lockForUpdate()
            ->first();

        if ($receivable === null) {
            throw PaymentReversalNotAllowed::notFound();
        }

        if ($receivable->status === 'void') {
            throw PaymentReversalNotAllowed::receivableVoided();
        }

        // Kembalikan sisa saldo; jangan pernah melebihi nilai awal.
        $restored = min(
            (int) $receivable->original_amount_minor,
            (int) $receivable->remaining_minor + (int) $payment->amount_minor,
        );

        $receivable->remaining_minor = $restored;
        $receivable->status = 'open';
        $receivable->save();

        $payment->fill([
            'reversed_at' => now(),
            'reversed_by' => $data['user_id'] ?? null,
            'reversal_reason' => $reason,
        ])->save();

        // Cermin saldo pelanggan (derived cache, hanya disentuh aksi domain).
        if ($receivable->customer_id) {
            $customer = Customer::query()->withoutGlobalScopes()->find($receivable->customer_id);

            if ($customer) {
                $customer->increment('balance', (new Money((int) $payment->amount_minor))->major());
            }
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::ReceivablePaymentCorrected->value,
            'entity_type' => 'receivable_payment',
            'entity_id' => $payment->uuid,
            'reason' => $reason,
            'metadata' => [
                'receivable_id' => $receivable->uuid,
                'transaction_id' => $receivable->transaction_id,
                'amount_minor' => $payment->amount_minor,
                'remaining_minor' => $receivable->remaining_minor,
                'payment_status' => $receivable->status,
            ],
        ]);

        return $payment;
    }
}
