<?php

namespace Tests\Feature\Purchases;

use App\Enums\FeatureProfile;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(): array
    {
        $tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::Retail->value,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('pemilik');

        return [$tenant, $user];
    }

    public function test_purchase_order_index_page_works(): void
    {
        [$tenant, $user] = $this->makeUser();
        $supplier = Supplier::factory()->forTenant($tenant)->create();
        PurchaseOrder::factory()->count(3)->forTenant($tenant)->create(['supplier_id' => $supplier->id]);

        $response = $this->actingAs($user)->get('/purchases');

        $response->assertStatus(200);
    }

    public function test_create_purchase_order_page_works(): void
    {
        [$tenant, $user] = $this->makeUser();

        $response = $this->actingAs($user)->get('/purchases/create');

        $response->assertStatus(200);
    }

    public function test_purchase_order_show_page_works(): void
    {
        [$tenant, $user] = $this->makeUser();
        $supplier = Supplier::factory()->forTenant($tenant)->create();
        $po = PurchaseOrder::factory()->forTenant($tenant)->create(['supplier_id' => $supplier->id]);

        $response = $this->actingAs($user)->get('/purchases/'.$po->id);

        $response->assertStatus(200);
        $response->assertSee($po->order_number);
    }
}
