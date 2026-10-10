<?php

namespace Tests\Feature\Features;

use App\Actions\Tenants\ConfigureFeatureProfile;
use App\Enums\FeatureProfile;
use App\Enums\Module;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Tenants\TenantNotFound;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Features\FeatureProfileManager;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConfigureFeatureProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ConfigureFeatureProfile $configure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['feature_profile' => FeatureProfile::Simple]);
        app(TenantContext::class)->set($this->tenant);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->configure = new ConfigureFeatureProfile($this->tenant->id);
    }

    public function test_apply_profile_sets_profile_and_resets_modules(): void
    {
        $tenant = $this->configure->applyProfile($this->owner, FeatureProfile::Retail);

        $this->assertSame(FeatureProfile::Retail, $tenant->feature_profile);
        $this->assertSame(FeatureProfile::Retail->modules(), $tenant->enabled_modules);

        $log = AuditLog::query()->where('action', 'feature_profile.changed')->firstOrFail();
        $this->assertSame('retail', $log->metadata['profile']);
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame('tenant', $log->entity_type);
    }

    public function test_apply_profile_requires_permission(): void
    {
        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashier->assignRole('cashier');

        $this->expectException(ActionForbidden::class);

        $this->configure->applyProfile($cashier, FeatureProfile::Retail);
    }

    public function test_disable_and_enable_module_never_deletes_data(): void
    {
        Product::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);

        // Matikan modul produk.
        $this->configure->disableModule($this->owner, Module::Products);

        $this->assertFalse(FeatureProfileManager::for($this->tenant->fresh())->isEnabled(Module::Products));
        // Data produk TIDAK terhapus meski modulnya nonaktif (FR-13).
        $this->assertSame(2, Product::withoutGlobalScope('tenant')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'module.disabled']);

        // Aktifkan kembali → akses pulih, data tetap ada.
        $this->configure->enableModule($this->owner, Module::Products);

        $this->assertTrue(FeatureProfileManager::for($this->tenant->fresh())->isEnabled(Module::Products));
        $this->assertSame(2, Product::withoutGlobalScope('tenant')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'module.enabled']);
    }

    public function test_enable_and_disable_are_idempotent_without_duplicate_audit(): void
    {
        $this->configure->enableModule($this->owner, Module::Stock);
        $this->configure->enableModule($this->owner, Module::Stock);

        $this->assertSame(1, AuditLog::query()->where('action', 'module.enabled')->count());

        $this->configure->disableModule($this->owner, Module::Stock);
    }

    public function test_enable_module_extends_effective_modules(): void
    {
        // Profil Simple belum punya modul pembelian.
        $this->assertFalse(FeatureProfileManager::for($this->tenant)->isEnabled(Module::Purchases));

        $this->configure->enableModule($this->owner, Module::Purchases, 'Aktifkan pembelian');

        $manager = FeatureProfileManager::for($this->tenant->fresh());
        $this->assertTrue($manager->isEnabled(Module::Purchases));

        $log = AuditLog::query()->where('action', 'module.enabled')->firstOrFail();
        $this->assertSame('purchases', $log->metadata['module']);
        $this->assertSame('Aktifkan pembelian', $log->reason);
    }

    public function test_configure_updates_business_type_and_complexity(): void
    {
        $this->configure->configure(
            $this->owner,
            businessType: 'retail',
            uiComplexity: 'advanced',
            onboardingState: 'completed',
        );

        $tenant = $this->tenant->fresh();
        $this->assertSame('retail', $tenant->business_type);
        $this->assertSame('advanced', $tenant->ui_complexity);
        $this->assertSame('completed', $tenant->onboarding_state);

        $log = AuditLog::query()->where('action', 'tenant.configured')->firstOrFail();
        $this->assertSame('advanced', $log->metadata['ui_complexity']);
    }

    public function test_configure_without_changes_is_noop(): void
    {
        $this->configure->configure(
            $this->owner,
            businessType: $this->tenant->business_type,
            uiComplexity: $this->tenant->ui_complexity,
            onboardingState: $this->tenant->onboarding_state,
        );

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_unknown_tenant_throws_not_found(): void
    {
        $action = new ConfigureFeatureProfile('00000000-0000-0000-0000-000000000000');

        $this->expectException(TenantNotFound::class);

        $action->applyProfile($this->owner, FeatureProfile::Retail);
    }

    public function test_only_target_tenant_is_changed(): void
    {
        $other = Tenant::factory()->create(['feature_profile' => FeatureProfile::Simple]);

        $this->configure->applyProfile($this->owner, FeatureProfile::Retail);

        $this->assertSame(FeatureProfile::Retail, $this->tenant->fresh()->feature_profile);
        $this->assertSame(FeatureProfile::Simple, $other->fresh()->feature_profile);
    }
}
