<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'product_id',
        'yield_quantity',
        'yield_unit_id',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:4',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function yieldUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'yield_unit_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }
}
