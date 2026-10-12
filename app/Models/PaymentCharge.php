<?php

namespace App\Models;

use App\Enums\PaymentVerification;
use App\Models\Concerns\BelongsToTenant;
use App\Payments\ProviderPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaksi pembayaran di penyedia (Mode B — Gateway, plan §14
 * "Penyimpanan reference ID provider").
 *
 * Baris ini adalah jembatan antara penjualan lokal (§17) dan penyedia:
 * `provider_reference` dari penyedia dipakai webhook/rekonsiliasi untuk
 * menemukan penjualan kita, sementara `client_request_id` (unik per penyedia)
 * membuat pembuatan transaksi idempoten (FR-04).
 *
 * `status` memakai kosakata netral penyedia (`ProviderPaymentStatus`) dan
 * `payment_verification` kosakata domain kita (FR-05) — keduanya disimpan agar
 * perbedaan antara "apa kata penyedia" dan "apa yang kita yakini" tetap
 * terlihat saat rekonsiliasi.
 */
class PaymentCharge extends Model
{
    use BelongsToTenant;

    protected $table = 'payment_charges';

    protected $attributes = [
        'status' => 'pending',
        'currency' => 'IDR',
    ];

    protected $fillable = [
        'tenant_id', 'transaction_id', 'provider', 'provider_reference',
        'client_request_id', 'status', 'payment_verification', 'amount_minor',
        'currency', 'payload', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProviderPaymentStatus::class,
            'payment_verification' => PaymentVerification::class,
            'amount_minor' => 'integer',
            'payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @param  Builder<PaymentCharge>  $query */
    public function scopeForProvider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }

    /** @param  Builder<PaymentCharge>  $query */
    public function scopeWithReference(Builder $query, string $reference): void
    {
        $query->where('provider_reference', $reference);
    }

    public function isPending(): bool
    {
        return $this->status === ProviderPaymentStatus::Pending;
    }

    /**
     * Perbarui status penyedia + verifikasi domain, lalu catat kapan status ini
     * terakhir diselaraskan (dipakai rekonsiliasi).
     */
    public function applyProviderStatus(ProviderPaymentStatus $status, ?PaymentVerification $verification): self
    {
        $this->status = $status;
        $this->last_synced_at = now();

        if ($verification !== null) {
            $this->payment_verification = $verification;
        }

        $this->save();

        return $this;
    }
}
