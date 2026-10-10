<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Piutang dasar (FR-07): satu receivable per penjualan kredit.
 *
 * `remaining_minor` hanya boleh diubah lewat domain actions — membuat
 * receivable (CreateSale) dan mencatat pembayaran (RecordReceivablePayment) —
 * tidak boleh diedit langsung (FR-07: aturan "saldo piutang tidak boleh
 * diubah dengan mengedit angka secara langsung").
 */
class Receivable extends Model
{
    use BelongsToTenant, HasFactory, HasSyncIdentity;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'customer_id',
        'transaction_id',
        'original_amount_minor',
        'remaining_minor',
        'due_at',
        'status',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'original_amount_minor' => 'integer',
            'remaining_minor' => 'integer',
            'due_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class);
    }
}
