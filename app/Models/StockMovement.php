<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id', 'quantity_change', 'before_stock', 'after_stock',
        'reference_type', 'reference_id', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'before_stock' => 'integer',
            'after_stock' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
