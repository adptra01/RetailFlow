<?php

namespace Tests\Feature\Layout;

use App\Enums\FeatureProfile;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SidebarFeatureProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_simple_profile_hides_purchases_and_customers(): void
    {
        $tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::Simple->value,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('pemilik');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertDontSee('/purchases');
        $response->assertDontSee('/customers');
    }

    public function test_credit_sales_profile_shows_customers_and_receivables(): void
    {
        $tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::CreditSales->value,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('pemilik');
        $user->refresh();

        // Debug: check user permissions
        \Log::info('User roles', ['roles' => $user->getRoleNames()->toArray()]);
        \Log::info('User permissions', ['permissions' => $user->getAllPermissions()->pluck('name')->toArray()]);
        \Log::info('Tenant profile', ['profile' => $tenant->feature_profile]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('/customers');
        $response->assertSee('/payments/customer');
        $response->assertDontSee('/purchases');
    }
}
