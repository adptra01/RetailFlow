<?php

namespace Tests\Feature\Foundation;

use App\Enums\FeatureProfile;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\FeatureFlag;
use App\Models\Location;
use App\Models\Tenant;
use App\Support\Features\FeatureProfileManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_tenant_uses_uuid_primary_key(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertTrue(Str::isUuid($tenant->id));
        $this->assertFalse($tenant->getIncrementing());
        $this->assertSame('string', $tenant->getKeyType());
    }

    public function test_tenant_casts_feature_profile_and_modules(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(FeatureProfile::Simple, $tenant->feature_profile);
        $this->assertNull($tenant->enabled_modules);

        // Verify FeatureProfileManager resolves modules from profile when enabled_modules is null
        $manager = FeatureProfileManager::for($tenant);
        $this->assertSame(['cashier', 'products', 'history', 'reports'], $manager->moduleNames());
    }

    public function test_feature_profiles_expose_modules(): void
    {
        $this->assertContains('stock', FeatureProfile::Retail->modules());
        $this->assertContains('receivables', FeatureProfile::CreditSales->modules());
        $this->assertSame([], array_diff(
            FeatureProfile::Simple->modules(),
            FeatureProfile::Advanced->modules(),
        ));
    }

    public function test_locations_and_devices_belong_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $location = Location::factory()->for($tenant)->create();
        $device = Device::factory()->for($tenant)->for($location)->create();

        $this->assertTrue($tenant->locations()->whereKey($location->id)->exists());
        $this->assertTrue($tenant->devices()->whereKey($device->id)->exists());
        $this->assertTrue($device->location->is($location));
        $this->assertFalse($device->isRevoked());
    }

    public function test_feature_flag_key_is_unique_per_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        FeatureFlag::factory()->for($tenant)->create(['key' => 'receivables']);

        $this->expectException(QueryException::class);

        FeatureFlag::factory()->for($tenant)->create(['key' => 'receivables']);
    }

    public function test_audit_log_is_append_only(): void
    {
        $log = AuditLog::create([
            'action' => 'sale.void',
            'entity_type' => 'sale',
            'reason' => 'customer changed mind',
            'metadata' => ['device' => 'pos-1'],
        ]);

        $this->assertTrue(Str::isUuid($log->id));
        $this->assertNotNull($log->created_at);
        $this->assertNull($log->updated_at);
        $this->assertSame(['device' => 'pos-1'], $log->metadata);
    }
}
