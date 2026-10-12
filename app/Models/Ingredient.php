<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Bahan baku untuk resep menu (modul F&B lanjutan).
 *
 * Harga satuan `cost_minor` adalah biaya per 1 unit dasar. Stok diukur dalam
 * satuan dasar integer (`stock_qty`) — konversi pecahan diatur lewat satuan
 * (mis. "gr", "ml", "pcs", "pack").
 */
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'name', 'slug', 'sku', 'unit', 'stock_qty', 'cost_minor',
        'min_stock_qty', 'is_active', 'notes', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'stock_qty' => 'integer',
            'cost_minor' => 'integer',
            'min_stock_qty' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Ingredient $ingredient) {
            if (empty($ingredient->slug)) {
                $ingredient->slug = Str::slug($ingredient->name);
            }
        });
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function cost(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cost_minor / 100,
        );
    }
}
