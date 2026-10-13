<?php

namespace App\Support\Api;

use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Support\Money\Money;

/**
 * Bentuk kiriman penjualan untuk klien perangkat (§18).
 *
 * Respons checkout WAJIB memuat (plan §18 "Respons harus menyertakan"): id
 * transaksi, nomor struk, total final, rincian pembayaran, sisa piutang bila
 * ada, dan status sinkronisasi (dikirim terpisah oleh controller). Seluruh
 * nominal dalam integer minor unit (§17.1); label bahasa Indonesia disertakan
 * agar klien tidak perlu menyalin kamus.
 */
final class SalePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Transaction $transaction, bool $withDetail = true): array
    {
        $payload = [
            'id' => $transaction->getKey(),
            'uuid' => $transaction->uuid,
            'invoice_number' => $transaction->invoice_number,
            'status' => $transaction->payment_status,
            'is_void' => $transaction->isVoided(),
            'is_credit' => $transaction->isCredit(),
            'payment_method' => $transaction->payment_method,
            'payment_method_label' => PaymentMethod::labelFor($transaction->payment_method),
            'payment_verification' => $transaction->payment_verification,
            'subtotal_minor' => (int) $transaction->subtotal_minor,
            'discount_minor' => (int) $transaction->discount_minor,
            'total_minor' => (int) $transaction->total_minor,
            'paid_minor' => (int) $transaction->paid_minor,
            'change_minor' => (int) $transaction->change_minor,
            'outstanding_minor' => max(0, (int) $transaction->total_minor - (int) $transaction->paid_minor),
            'customer' => $transaction->customer === null ? null : [
                'id' => $transaction->customer->getKey(),
                'name' => $transaction->customer->name,
                'balance_minor' => Money::fromMajor($transaction->customer->balance)->minor(),
            ],
            'customer_name' => $transaction->customer_name,
            'location_id' => $transaction->location_id,
            'device_id' => $transaction->device_id,
            'notes' => $transaction->notes,
            'void_reason' => $transaction->void_reason,
            'voided_at' => $transaction->voided_at?->toIso8601String(),
            'created_at' => $transaction->created_at?->toIso8601String(),
            'updated_at' => $transaction->updated_at?->toIso8601String(),
        ];

        if (! $withDetail) {
            return $payload;
        }

        $payload['items'] = $transaction->items
            ->map(fn (TransactionItem $item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'product_unit_id' => $item->product_unit_id,
                'unit_name' => $item->unit_name,
                'quantity' => (int) $item->quantity,
                'unit_price_minor' => (int) $item->unit_price_minor,
                'discount_minor' => (int) $item->discount_minor,
                'subtotal_minor' => (int) $item->subtotal_minor,
                'promotion_id' => $item->promotion_id,
                'promotion_name' => $item->promotion_name,
            ])
            ->values()
            ->all();

        $payload['payments'] = $transaction->payments
            ->map(fn (TransactionPayment $payment) => [
                'method' => $payment->payment_method,
                'method_label' => PaymentMethod::labelFor($payment->payment_method),
                'amount_minor' => (int) $payment->amount_minor,
                'verification' => $payment->payment_verification,
                'verification_label' => PaymentVerification::labelFor($payment->payment_verification),
                'reference' => $payment->reference,
            ])
            ->values()
            ->all();

        $payload['receivable'] = self::receivable($transaction->receivable);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function receivable(?Receivable $receivable): ?array
    {
        if ($receivable === null) {
            return null;
        }

        return [
            'id' => $receivable->getKey(),
            'uuid' => $receivable->uuid,
            'original_amount_minor' => (int) $receivable->original_amount_minor,
            'remaining_minor' => (int) $receivable->remaining_minor,
            'due_at' => $receivable->due_at?->toIso8601String(),
            'status' => $receivable->status,
        ];
    }
}
