<?php

namespace Database\Factories;

use App\Models\FeatureFlag;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureFlag>
 */
class FeatureFlagFactory extends Factory
{
    protected $model = FeatureFlag::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'key' => fake()->unique()->word(),
            'enabled' => true,
            'version' => 1,
        ];
    }
}
