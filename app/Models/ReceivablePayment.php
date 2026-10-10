<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan pembayaran piutang (FR-07: "setiap pembayaran piutang harus
 * memiliki catatan tersendiri"). Append-only secara domain: koreksi dibuat
 * lewat record koreksi yang diaudit, bukan edit pada record ini.
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
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
