<?php

namespace App\Models;

use App\Enums\SyncInboxStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operasi sinkronisasi yang diterima server (§11.3). Cermin sisi server dari
 * `SyncOutbox`; `operation_id` unik membuat penerimaan ulang bersifat
 * idempoten (§11.1 "aman terhadap pengiriman ulang").
 *
 * `id` (bigint naik) adalah penanda urutan server yang monoton.
 */
class SyncInbox extends Model
{
    protected $table = 'sync_inbox';

    protected $fillable = [
        'operation_id', 'tenant_id', 'device_id', 'entity_type', 'entity_id',
        'operation_type', 'payload', 'local_sequence', 'status', 'error',
        'received_at', 'applied_at', 'applied_entity_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => SyncInboxStatus::class,
            'local_sequence' => 'integer',
            'received_at' => 'datetime',
            'applied_at' => 'datetime',
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

    /** @param  Builder<SyncInbox>  $query */
    public function scopeForDevice(Builder $query, string $deviceId): void
    {
        $query->where('device_id', $deviceId);
    }

    /** @param  Builder<SyncInbox>  $query */
    public function scopeWithStatus(Builder $query, SyncInboxStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * Penanda urutan server (monoton) untuk operasi ini.
     */
    public function serverSequence(): int
    {
        return (int) $this->getKey();
    }

    public function isReceived(): bool
    {
        return $this->status === SyncInboxStatus::Received;
    }

    public function markApplied(?string $entityId = null): self
    {
        $this->status = SyncInboxStatus::Applied;
        $this->applied_at = now();
        $this->applied_entity_id = $entityId;
        $this->error = null;
        $this->save();

        return $this;
    }

    public function markRejected(string $error): self
    {
        $this->status = SyncInboxStatus::Rejected;
        $this->error = $error;
        $this->save();

        return $this;
    }

    public function markConflict(string $error): self
    {
        $this->status = SyncInboxStatus::Conflict;
        $this->error = $error;
        $this->save();

        return $this;
    }
}
