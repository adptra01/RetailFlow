<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Baris komposisi resep: berapa banyak bahan tertentu untuk 1 porsi menu.
 */
class RecipeIngredient extends Model
{
    use BelongsToTenant, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'menu_id', 'ingredient_id', 'quantity', 'notes', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'version' => 'integer',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
