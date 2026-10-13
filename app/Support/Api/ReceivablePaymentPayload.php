<?php

namespace App\Support\Api;

use App\Enums\PaymentMethod;
use App\Models\ReceivablePayment;

/**
 * Bentuk kiriman pembayaran piutang untuk klien perangkat (§18, FR-07).
 *
 * Nominal dalam integer minor unit; koreksi (reversal) dilaporkan apa adanya
 * agar klien tidak menganggap pembayaran yang sudah dikoreksi masih berlaku
 * (FR-07: koreksi meninggalkan jejak, bukan menghapus).
 */
final class ReceivablePaymentPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ReceivablePayment $payment): array
    {
        return [
            'id' => $payment->getKey(),
            'uuid' => $payment->uuid,
            'receivable_id' => $payment->receivable_id,
            'amount_minor' => (int) $payment->amount_minor,
            'payment_method' => $payment->payment_method,
            'payment_method_label' => PaymentMethod::labelFor($payment->payment_method),
            'recorded_by' => $payment->recorded_by,
            'client_request_id' => $payment->client_request_id,
            'notes' => $payment->notes,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'is_reversed' => $payment->isReversed(),
            'reversed_at' => $payment->reversed_at?->toIso8601String(),
            'reversal_reason' => $payment->reversal_reason,
        ];
    }
}
