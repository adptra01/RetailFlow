<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'supplier_id', 'user_id', 'status', 'notes',
        'subtotal_minor', 'discount_minor', 'total_minor',
        'location_id', 'device_id', 'client_request_id', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $po) {
            if (empty($po->order_number)) {
                $po->order_number = 'PO-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->subtotal_minor / 100,
        );
    }

    public function discount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->discount_minor / 100,
        );
    }

    public function total(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->total_minor / 100,
        );
    }
}
