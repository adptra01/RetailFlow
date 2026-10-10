<?php

namespace App\Models;

use App\Support\Devices\DeviceToken;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'location_id', 'device_name', 'platform', 'app_version',
        'token_hash', 'token_prefix', 'last_seen_at', 'revoked_at', 'last_sync_cursor',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Temukan perangkat aktif (belum dicabut) dari token plaintext.
     *
     * Perangkat yang dicabut TIDAK akan pernah cocok sehingga ditolak pada
     * sinkronisasi berikutnya (§15).
     */
    public static function findByToken(string $plaintext): ?self
    {
        return static::query()
            ->where('token_hash', DeviceToken::hash($plaintext))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Tandai perangkat baru saja terlihat (heartbeat sinkronisasi).
     */
    public function markSeen(?string $appVersion = null): void
    {
        $attributes = ['last_seen_at' => now()];

        if ($appVersion !== null) {
            $attributes['app_version'] = $appVersion;
        }

        $this->update($attributes);
    }
}
