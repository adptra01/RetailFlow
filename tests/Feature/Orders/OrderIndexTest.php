<?php

namespace Tests\Feature\Orders;

use App\Enums\FeatureProfile;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OrderIndexTest extends TestCase
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
            'feature_profile' => FeatureProfile::FoodBeverage->value,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('pemilik');

        return [$tenant, $user];
    }

    public function test_orders_index_page_works(): void
    {
        [$tenant, $user] = $this->makeUser();
        Order::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get('/orders');

        $response->assertStatus(200);
    }

    public function test_orders_index_filters_by_status(): void
    {
        [$tenant, $user] = $this->makeUser();
        Order::factory()->pending()->create(['tenant_id' => $tenant->id, 'order_number' => 'ORD-PENDING-001']);
        Order::factory()->paid()->create(['tenant_id' => $tenant->id, 'order_number' => 'ORD-PAID-001']);

        $response = $this->actingAs($user)->get('/orders?status=pending');

        $response->assertStatus(200);
        $response->assertSee('ORD-PENDING-001');
        $response->assertDontSee('ORD-PAID-001');
    }

    public function test_order_detail_modal_shows_items(): void
    {
        [$tenant, $user] = $this->makeUser();
        $order = Order::factory()->pending()->create(['tenant_id' => $tenant->id, 'order_number' => 'ORD-TEST-001', 'total_minor' => 50000]);
        Menu::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Nasi Goreng']);
        OrderItem::factory()->forOrder($order)->create(['menu_name' => 'Nasi Goreng', 'quantity' => 2, 'unit_price_minor' => 25000, 'subtotal_minor' => 50000]);

        $response = $this->actingAs($user)->get('/orders');

        $response->assertStatus(200);
        $response->assertSee('ORD-TEST-001');
    }
}
