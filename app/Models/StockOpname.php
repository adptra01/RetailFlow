<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpname extends Model
{
    protected $fillable = [
        'product_id', 'system_stock', 'actual_stock',
        'difference', 'reason', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'system_stock' => 'integer',
            'actual_stock' => 'integer',
            'difference' => 'integer',
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
