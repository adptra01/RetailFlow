<?php

namespace Database\Factories;

use App\Enums\FeatureProfile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'business_type' => 'retail',
            'feature_profile' => FeatureProfile::Simple,
            'ui_complexity' => 'simple',
            'onboarding_state' => 'completed',
            'enabled_modules' => null,
            'currency_code' => 'IDR',
            'timezone' => 'Asia/Jakarta',
        ];
    }
}
