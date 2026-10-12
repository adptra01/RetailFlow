<?php

namespace Tests\Feature\Ingredients;

use App\Enums\FeatureProfile;
use App\Models\Ingredient;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Halaman `/ingredients` (Increment 40): CRUD bahan baku + visibilitas
 * modul di sidebar profil F&B.
 */
class IngredientPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Flux modal memakai @teleport (banyak elemen akar); matikan debug
        // agar Livewire::test tidak menolak render.
        config(['app.debug' => false]);
    }

    /** @return array{Tenant, User} */
    private function makeUser(FeatureProfile $profile = FeatureProfile::FoodBeverage): array
    {
        $tenant = Tenant::factory()->create(['feature_profile' => $profile->value]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('pemilik');

        return [$tenant, $user];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/ingredients')->assertRedirect(route('login'));
    }

    public function test_page_renders_with_ingredients(): void
    {
        [$tenant, $user] = $this->makeUser();
        Ingredient::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Tepung Terigu']);

        $this->actingAs($user)
            ->get('/ingredients')
            ->assertOk()
            ->assertSee('Tepung Terigu');
    }

    public function test_can_create_ingredient_via_volt(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        Livewire::test('ingredients.index')
            ->call('openCreateModal')
            ->set('name', 'Gula Pasir')
            ->set('unit', 'gr')
            ->set('cost_minor', 1500)
            ->set('stock_qty', 1000)
            ->set('min_stock_qty', 100)
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'name' => 'Gula Pasir',
            'unit' => 'gr',
            'cost_minor' => 1500,
            'stock_qty' => 1000,
            'min_stock_qty' => 100,
        ]);
    }

    public function test_create_requires_name_and_unit(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        Livewire::test('ingredients.index')
            ->call('openCreateModal')
            ->set('unit', '')
            ->call('save')
            ->assertHasErrors(['name', 'unit']);
    }

    public function test_can_edit_ingredient_via_volt(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        $ingredient = Ingredient::factory()->create([
            'tenant_id' => $tenant->id, 'name' => 'Tepung', 'cost_minor' => 1000,
        ]);

        Livewire::test('ingredients.index')
            ->call('openEditModal', $ingredient->id)
            ->set('cost_minor', 2500)
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'cost_minor' => 2500,
        ]);
    }

    public function test_can_delete_ingredient_via_volt(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        $ingredient = Ingredient::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::test('ingredients.index')
            ->call('confirmDelete', $ingredient->id)
            ->assertOk();

        $this->assertSoftDeleted('ingredients', ['id' => $ingredient->id]);
    }

    public function test_sidebar_shows_bahan_baku_for_food_beverage_profile(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Bahan Baku');
    }

    public function test_sidebar_hides_bahan_baku_when_module_absent(): void
    {
        // Profil Retail tidak punya modul Makanan & Minuman/Bahan Baku.
        [$tenant, $user] = $this->makeUser(FeatureProfile::Retail);
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Bahan Baku');
    }
}
