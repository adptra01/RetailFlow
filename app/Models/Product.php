<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'slug', 'sku',
        'price', 'stock', 'image', 'description', 'is_active',
        'is_unlimited_stock',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_unlimited_stock' => 'boolean',
            'price' => 'float',
        ];
    }

    public function getImageUrlAttribute(): string
    {
        if (! $this->image) {
            return asset('images/product-default.svg');
        }

        if (str_starts_with($this->image, 'http')) {
            return $this->image;
        }

        if (file_exists(storage_path('app/public/'.$this->image))) {
            return asset('storage/'.$this->image);
        }

        return asset('images/product-default.svg');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }
}
