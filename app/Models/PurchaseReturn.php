<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\PurchaseReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PurchaseReturn extends Model
{
    /** @use HasFactory<PurchaseReturnFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'purchase_order_id', 'supplier_id', 'reason',
        'subtotal_minor', 'discount_minor', 'total_minor',
        'user_id', 'location_id', 'device_id', 'client_request_id', 'tenant_id',
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
        static::creating(function (PurchaseReturn $return) {
            if (empty($return->return_number)) {
                $return->return_number = 'RTP-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
            }
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
        return $this->hasMany(PurchaseReturnItem::class);
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
