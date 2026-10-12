<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'sku',
        'barcode',
        'description',
        'price_minor',
        'preparation_time',
        'is_available',
        'is_featured',
        'image',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'preparation_time' => 'integer',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->with('ingredient');
    }

    /**
     * HPP (cost of goods sold) untuk 1 porsi menu — sum(qty bahan x cost satuan).
     */
    public function cogsMinor(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) $this->recipeIngredients->sum(
                fn ($line) => $line->quantity * ($line->ingredient?->cost_minor ?? 0),
            ),
        );
    }

    public function hasRecipe(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->recipeIngredients->isNotEmpty(),
        );
    }

    public function price(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->price_minor / 100,
        );
    }
}
