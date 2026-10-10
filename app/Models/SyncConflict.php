<?php

namespace App\Models;

use App\Enums\SyncConflictStatus;
use App\Enums\SyncConflictType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kasus konflik sinkronisasi (§11.4). Dibuat server saat operasi perangkat
 * tidak dapat direkonsiliasi otomatis (mis. stok tidak mencukupi) sehingga
 * transaksi perangkat tidak dihapus diam-diam, melainkan menunggu
 * penyelesaian pengguna berwenang.
 *
 * Disinkronkan (uuid + version + journal) agar perangkat dapat menarik dan
 * menampilkan konflik di UI (§11.3 langkah 11).
 */
class SyncConflict extends Model
{
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'tenant_id',
        'device_id',
        'operation_id',
        'type',
        'status',
        'entity_type',
        'entity_id',
        'details',
        'resolution',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SyncConflictType::class,
            'status' => SyncConflictStatus::class,
            'details' => 'array',
            'resolution' => 'array',
            'resolved_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @param  Builder<SyncConflict>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', SyncConflictStatus::Open->value);
    }

    /** @param  Builder<SyncConflict>  $query */
    public function scopeOfType(Builder $query, SyncConflictType $type): void
    {
        $query->where('type', $type->value);
    }

    public function isOpen(): bool
    {
        return $this->status === SyncConflictStatus::Open;
    }
}
