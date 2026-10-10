<?php

namespace Database\Seeders;

use App\Enums\FeatureProfile;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['name' => 'Toko Default'],
            [
                'business_type' => 'retail',
                'feature_profile' => FeatureProfile::Simple,
                'ui_complexity' => 'simple',
                'onboarding_state' => 'completed',
                'enabled_modules' => FeatureProfile::Simple->modules(),
                'currency_code' => config('pos.currency.code'),
                'timezone' => 'Asia/Jakarta',
            ],
        );

        $tenant->locations()->firstOrCreate(['name' => 'Toko Utama']);
    }
}
