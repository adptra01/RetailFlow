<?php

namespace App\Models;

use App\Enums\PaymentVerification;
use App\Enums\PaymentWebhookOutcome;
use App\Payments\ProviderPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak callback/webhook pembayaran yang tanda tangannya sudah diverifikasi
 * (plan §14 "Callback/webhook handler" + "Idempotensi callback", §15).
 *
 * TIDAK memakai `BelongsToTenant`: webhook datang tanpa konteks tenant (§12),
 * sehingga scope tenant akan menyembunyikan baris tak bertuan yang justru perlu
 * diperiksa saat diagnostik. `tenant_id` diisi begitu referensi penyedia
 * berhasil dicocokkan dengan penjualan kita.
 *
 * `unique(provider, event_id)` adalah penjaga idempotensi; `outcome` merekam
 * apa yang terjadi pada tiap peristiwa agar pengiriman ulang dapat dibedakan
 * dari peristiwa baru.
 */
class PaymentWebhookEvent extends Model
{
    protected $table = 'payment_webhook_events';

    protected $fillable = [
        'tenant_id', 'payment_charge_id', 'transaction_id', 'provider', 'event_id',
        'provider_reference', 'provider_status', 'outcome', 'payment_verification',
        'reason', 'payload', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_status' => ProviderPaymentStatus::class,
            'outcome' => PaymentWebhookOutcome::class,
            'payment_verification' => PaymentVerification::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PaymentCharge::class, 'payment_charge_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @param  Builder<PaymentWebhookEvent>  $query */
    public function scopeForProvider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }

    /**
     * Tandai peristiwa selesai diproses dengan hasil akhirnya.
     */
    public function settle(
        PaymentWebhookOutcome $outcome,
        ?PaymentCharge $charge = null,
        ?PaymentVerification $verification = null,
        ?string $reason = null,
    ): self {
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->processed_at = now();

        if ($charge !== null) {
            $this->payment_charge_id = $charge->getKey();
            $this->transaction_id = $charge->transaction_id;
            $this->tenant_id = $charge->tenant_id;
            $this->payment_verification = $verification;
        }

        $this->save();

        return $this;
    }
}
