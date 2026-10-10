<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use Database\Factories\TransactionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    /** @use HasFactory<TransactionItemFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'transaction_id', 'product_id', 'product_unit_id', 'unit_name',
        'quantity', 'unit_price', 'subtotal', 'tenant_id',
        'unit_price_minor', 'discount_minor', 'subtotal_minor',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
