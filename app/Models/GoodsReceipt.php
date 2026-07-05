<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GoodsReceipt extends Model
{
    protected $fillable = ['receipt_number', 'purchase_order_id', 'user_id', 'notes'];

    protected static function booted(): void
    {
        static::creating(function (GoodsReceipt $gr) {
            if (empty($gr->receipt_number)) {
                $gr->receipt_number = 'GR-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
            }
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
