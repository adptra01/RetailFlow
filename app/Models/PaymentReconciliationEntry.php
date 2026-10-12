<?php

namespace App\Models;

use App\Enums\PaymentReconciliationOutcome;
use App\Enums\PaymentVerification;
use App\Payments\ProviderPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil pemeriksaan ulang charge pada satu putaran rekonsiliasi (plan §14).
 *
 * Append-only dan tidak memakai `BelongsToTenant` (sama seperti
 * `PaymentWebhookEvent`): rekonsiliasi umumnya berjalan sebagai perintah CLI
 * tanpa konteks tenant (§12), jadi `tenant_id` diisi dari charge.
 */
class PaymentReconciliationEntry extends Model
{
    protected $table = 'payment_reconciliation_entries';

    public $timestamps = false;

    protected $fillable = [
        'run_id', 'tenant_id', 'payment_charge_id', 'transaction_id', 'provider',
        'provider_reference', 'status_before', 'status_after', 'verification_before',
        'verification_after', 'outcome', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status_before' => ProviderPaymentStatus::class,
            'status_after' => ProviderPaymentStatus::class,
            'verification_before' => PaymentVerification::class,
            'verification_after' => PaymentVerification::class,
            'outcome' => PaymentReconciliationOutcome::class,
            'created_at' => 'datetime',
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

    /** @param  Builder<PaymentReconciliationEntry>  $query */
    public function scopeForRun(Builder $query, string $runId): void
    {
        $query->where('run_id', $runId);
    }
}
