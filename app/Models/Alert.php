<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Alert lokal (FR-10): notifikasi berbasis kondisi lokal, deduplikasi via
 * `deduplication_key` terhadap alert yang belum dibaca, prioritas via
 * `severity`, dan payload berisi aksi yang disarankan + target navigasi.
 */
class Alert extends Model
{
    use BelongsToTenant, HasFactory, HasSyncIdentity;

    public const TYPE_STOCK_LOW = 'stock.low';

    public const TYPE_PRODUCT_INCOMPLETE_DATA = 'product.incomplete_data';

    public const TYPE_RECEIVABLE_DUE = 'receivable.due';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'tenant_id',
        'device_id',
        'alert_type',
        'deduplication_key',
        'severity',
        'payload',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function markAsRead(): void
    {
        $this->update(['read_at' => now()]);
    }
}
