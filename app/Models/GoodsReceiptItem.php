<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\GoodsReceiptItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceiptItem extends Model
{
    /** @use HasFactory<GoodsReceiptItemFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'goods_receipt_id', 'purchase_order_item_id',
        'quantity', 'unit_price_minor', 'subtotal_minor', 'notes',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function unitPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->unit_price_minor / 100,
        );
    }

    public function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->subtotal_minor / 100,
        );
    }
}
