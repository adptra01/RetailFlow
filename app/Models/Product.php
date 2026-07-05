<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id', 'brand_id', 'supplier_id', 'name', 'slug', 'sku', 'barcode',
        'buy_price', 'price', 'stock', 'min_stock', 'image', 'description',
        'is_active', 'is_unlimited_stock',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_unlimited_stock' => 'boolean',
            'buy_price' => 'decimal:2',
            'price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
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

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function baseUnit(): ?ProductUnit
    {
        return $this->units()->where('is_base', true)->first();
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockOpnames(): HasMany
    {
        return $this->hasMany(StockOpname::class);
    }

    public function totalStockLabel(): Attribute
    {
        return Attribute::make(
            get: function () {
                $base = $this->units()->where('is_base', true)->first();
                if (! $base) {
                    return (string) $this->stock;
                }

                $remaining = $this->stock;
                $parts = [];

                foreach ($this->units()->where('is_base', false)->orderBy('conversion_factor', 'desc')->get() as $unit) {
                    $qty = intdiv($remaining, $unit->conversion_factor);
                    if ($qty > 0) {
                        $parts[] = "{$qty} {$unit->abbreviation}";
                        $remaining %= $unit->conversion_factor;
                    }
                }

                $parts[] = "{$remaining} {$base->abbreviation}";

                return implode(' + ', $parts);
            }
        );
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
