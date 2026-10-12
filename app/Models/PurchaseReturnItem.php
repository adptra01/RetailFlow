<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\PurchaseReturnItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturnItem extends Model
{
    /** @use HasFactory<PurchaseReturnItemFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'purchase_return_id', 'product_id', 'product_unit_id',
        'qty', 'price_minor', 'discount_minor', 'subtotal_minor', 'reason',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'price_minor' => 'integer',
            'discount_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function price(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->price_minor / 100,
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
