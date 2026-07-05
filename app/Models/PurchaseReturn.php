<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseReturn extends Model
{
    protected $fillable = [
        'return_number', 'purchase_order_id', 'supplier_id',
        'total', 'reason', 'user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseReturn $return) {
            if (empty($return->return_number)) {
                $return->return_number = 'RTP-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
        ];
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
}
