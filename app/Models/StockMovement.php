<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToTenant, HasSyncIdentity;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'product_id', 'quantity_change', 'before_stock', 'after_stock',
        'reference_type', 'reference_id', 'user_id', 'notes', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'before_stock' => 'integer',
            'after_stock' => 'integer',
            'version' => 'integer',
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
