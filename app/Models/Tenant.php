<?php

namespace App\Models;

use App\Enums\FeatureProfile;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'business_type', 'feature_profile', 'ui_complexity',
        'onboarding_state', 'enabled_modules', 'currency_code', 'timezone',
    ];

    protected function casts(): array
    {
        return [
            'feature_profile' => FeatureProfile::class,
            'enabled_modules' => 'array',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function featureFlags(): HasMany
    {
        return $this->hasMany(FeatureFlag::class);
    }
}
