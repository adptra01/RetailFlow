<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan pembayaran piutang (FR-07: "setiap pembayaran piutang harus
 * memiliki catatan tersendiri"). Append-only secara domain: koreksi dibuat
 * lewat aksi `ReverseReceivablePayment` (menandai `reversed_at` + alasan +
 * aktor) dan terekam di audit, bukan dengan menghapus atau mengedit nominal.
 */
class ReceivablePayment extends Model
{
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'receivable_id',
        'payment_id',
        'amount_minor',
        'payment_method',
        'recorded_by',
        'client_request_id',
        'notes',
        'paid_at',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
            'reversed_by' => 'integer',
            'version' => 'integer',
        ];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** Pembayaran yang masih berlaku (belum dikoreksi). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
