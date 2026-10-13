<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan penagihan piutang (FR-07: "Catatan penagihan").
 *
 * Append-only secara domain: satu catatan = satu aktivitas penagihan atas satu
 * piutang. Perbaikan ditulis sebagai catatan baru, bukan dengan mengubah atau
 * menghapus baris lama.
 */
class ReceivableNote extends Model
{
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'receivable_id',
        'channel',
        'note',
        'contacted_at',
        'created_by',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'contacted_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
