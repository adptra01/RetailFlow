<?php

namespace App\Models;

use Database\Factories\FeatureFlagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureFlag extends Model
{
    /** @use HasFactory<FeatureFlagFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'key', 'enabled', 'version'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
