<?php

namespace Tests\Feature\Menu;

use App\Enums\FeatureProfile;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MenuIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_menu_index_page_works(): void
    {
        $tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::FoodBeverage->value,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('pemilik');

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        Menu::factory()->count(3)->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);

        $response = $this->actingAs($user)->get('/menu');

        $response->assertStatus(200);
    }

    public function test_menu_index_shows_featured_badge(): void
    {
        $tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::FoodBeverage->value,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('pemilik');

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        Menu::factory()->featured()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Nasi Goreng Spesial']);

        $response = $this->actingAs($user)->get('/menu');

        $response->assertStatus(200);
        $response->assertSee('Featured');
        $response->assertSee('Nasi Goreng Spesial');
    }
}
