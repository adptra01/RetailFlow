<?php

namespace Tests\Feature\Features;

use App\Enums\FeatureProfile;
use App\Enums\Module;
use App\Models\Tenant;
use App\Support\Features\FeatureProfileManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FeatureProfileManagerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function tenant(array $attributes = []): Tenant
    {
        return Tenant::factory()->create($attributes);
    }

    public function test_uses_explicit_enabled_modules_when_present(): void
    {
        $tenant = $this->tenant(['enabled_modules' => ['cashier', 'stock', 'custom_module_not_in_catalog']]);

        $modules = FeatureProfileManager::for($tenant)->modules();

        $this->assertSame([Module::Cashier, Module::Stock], $modules);
    }

    public function test_falls_back_to_profile_defaults_when_null(): void
    {
        $tenant = $this->tenant([
            'feature_profile' => FeatureProfile::Retail,
            'enabled_modules' => null,
        ]);

        $names = FeatureProfileManager::for($tenant)->moduleNames();

        $this->assertSame(FeatureProfile::Retail->modules(), $names);
    }

    public function test_falls_back_to_profile_defaults_when_empty(): void
    {
        $tenant = $this->tenant([
            'feature_profile' => FeatureProfile::Simple,
            'enabled_modules' => [],
        ]);

        $this->assertSame(FeatureProfile::Simple->modules(), FeatureProfileManager::for($tenant)->moduleNames());
    }

    public function test_is_enabled_and_has(): void
    {
        $tenant = $this->tenant([
            'feature_profile' => FeatureProfile::Simple,
            'enabled_modules' => null,
        ]);

        $manager = FeatureProfileManager::for($tenant);

        $this->assertTrue($manager->isEnabled(Module::Cashier));
        $this->assertFalse($manager->isEnabled(Module::Purchases));

        $this->assertTrue($manager->has('cashier'));
        $this->assertFalse($manager->has('purchases'));
        $this->assertFalse($manager->has('tidak_ada_modul'));
    }

    public function test_resolve_deduplicates_modules(): void
    {
        $modules = FeatureProfileManager::resolve(
            FeatureProfile::Simple,
            ['cashier', 'cashier', 'products'],
        );

        $this->assertSame([Module::Cashier, Module::Products], $modules);
    }
}
