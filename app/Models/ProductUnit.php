<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    protected $fillable = [
        'product_id', 'name', 'abbreviation', 'barcode', 'conversion_factor',
        'is_base', 'price', 'price_grosir', 'price_reseller', 'purchase_price', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
            'price_grosir' => 'decimal:2',
            'price_reseller' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'conversion_factor' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
