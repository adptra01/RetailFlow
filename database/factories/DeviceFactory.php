<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'location_id' => null,
            'device_name' => fake()->word(),
            'platform' => 'windows',
            'app_version' => '1.0.0',
        ];
    }
}
