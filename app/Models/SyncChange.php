<?php

namespace App\Models;

use App\Enums\SyncChangeOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris jurnal perubahan sisi server (§11.3 langkah 9). `sequence` (bigint
 * naik) adalah kursor sinkronisasi yang dipakai perangkat.
 *
 * Append-only: tidak ada `updated_at`.
 */
class SyncChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sync_changes';

    protected $primaryKey = 'sequence';

    protected $fillable = [
        'tenant_id', 'entity_type', 'entity_id', 'operation', 'payload', 'device_id',
    ];

    protected function casts(): array
    {
        return [
            'operation' => SyncChangeOperation::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @param  Builder<SyncChange>  $query */
    public function scopeForTenant(Builder $query, string $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }

    /** @param  Builder<SyncChange>  $query */
    public function scopeAfterSequence(Builder $query, int $sequence): void
    {
        $query->where('sequence', '>', $sequence);
    }
}
