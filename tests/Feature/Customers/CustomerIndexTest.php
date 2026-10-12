<?php

namespace Tests\Feature\Customers;

use App\Enums\FeatureProfile;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CustomerIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_index_page_works(): void
    {
        $tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::CreditSales->value,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('pemilik');

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertStatus(200);
        $response->assertSee($customer->name);
    }
}
