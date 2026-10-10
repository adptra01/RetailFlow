<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Support\Sync\Backoff;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antrean outbox sinkronisasi (§11.2, §17).
 *
 * `operation_id` (UUID) adalah identitas idempoten operasi; `local_sequence`
 * menjaga urutan per perangkat. Baris ditulis dalam transaksi yang sama
 * dengan perubahan domain (transactional outbox) sehingga tidak ada operasi
 * yang hilang saat crash (§11.1, §13).
 */
class SyncOutbox extends Model
{
    use HasUuids;

    protected $table = 'sync_outbox';

    protected $primaryKey = 'operation_id';

    protected $fillable = [
        'operation_id', 'tenant_id', 'device_id', 'entity_type', 'entity_id',
        'operation_type', 'payload', 'local_sequence', 'sync_status',
        'retry_count', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sync_status' => SyncStatus::class,
            'local_sequence' => 'integer',
            'retry_count' => 'integer',
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

    /** @param  Builder<SyncOutbox>  $query */
    public function scopeForDevice(Builder $query, string $deviceId): void
    {
        $query->where('device_id', $deviceId);
    }

    /** @param  Builder<SyncOutbox>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('sync_status', SyncStatus::Pending->value);
    }

    /** @param  Builder<SyncOutbox>  $query */
    public function scopeRetryable(Builder $query): void
    {
        $query->whereIn('sync_status', SyncStatus::retryableValues())
            ->where('retry_count', '<', (int) config('pos.sync.max_retries', 5));
    }

    public function isSynced(): bool
    {
        return $this->sync_status === SyncStatus::Synced;
    }

    public function isRetryable(): bool
    {
        return in_array($this->sync_status?->value, SyncStatus::retryableValues(), true)
            && $this->retry_count < (int) config('pos.sync.max_retries', 5);
    }

    public function markInFlight(): self
    {
        return $this->transitionTo(SyncStatus::InFlight);
    }

    public function markSynced(): self
    {
        $this->sync_status = SyncStatus::Synced;
        $this->last_error = null;
        $this->save();

        return $this;
    }

    public function markConflict(?string $error = null): self
    {
        $this->sync_status = SyncStatus::Conflict;
        $this->last_error = $error;
        $this->save();

        return $this;
    }

    /**
     * Tandai gagal: menambah `retry_count` dan menyimpan pesan terakhir.
     */
    public function markFailed(string $error): self
    {
        $this->sync_status = SyncStatus::Failed;
        $this->last_error = $error;
        $this->retry_count++;
        $this->save();

        return $this;
    }

    /**
     * Hasil pengiriman tidak diketahui (timeout/koneksi, §11.3). Operasi
     * tetap dapat diulang dan TIDAK dianggap gagal permanen: status kembali
     * `pending`, `retry_count` bertambah sebagai dasar backoff.
     */
    public function markRetryPending(string $error): self
    {
        return $this->transitionToRetry($error);
    }

    /**
     * Waktu paling awal entri ini boleh dicoba lagi, mengikuti backoff.
     */
    public function nextAttemptAt(): CarbonInterface
    {
        $base = $this->updated_at ?? $this->created_at ?? now();

        return $base->copy()->addSeconds(Backoff::delayFor($this->retry_count));
    }

    /**
     * Entri siap dicoba sekarang (backoff sudah lewat).
     */
    public function isReadyForAttempt(?CarbonInterface $now = null): bool
    {
        return $this->nextAttemptAt()->lessThanOrEqualTo($now ?? now());
    }

    private function transitionTo(SyncStatus $status): self
    {
        $this->sync_status = $status;
        $this->save();

        return $this;
    }

    private function transitionToRetry(string $error): self
    {
        $this->sync_status = SyncStatus::Pending;
        $this->last_error = $error;
        $this->retry_count++;
        $this->save();

        return $this;
    }
}
