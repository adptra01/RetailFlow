<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\PurchaseOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderItem extends Model
{
    /** @use HasFactory<PurchaseOrderItemFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_unit_id',
        'quantity', 'unit_price_minor', 'discount_minor', 'subtotal_minor', 'received_quantity',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'quantity' => 'integer',
            'received_quantity' => 'integer',
            'version' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function unitPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->unit_price_minor / 100,
        );
    }

    public function discount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->discount_minor / 100,
        );
    }

    public function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->subtotal_minor / 100,
        );
    }
}
