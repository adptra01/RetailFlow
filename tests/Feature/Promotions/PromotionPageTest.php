<?php

namespace Tests\Feature\Promotions;

use App\Enums\FeatureProfile;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Halaman `/promotions` (Increment 41): CRUD promosi + visibilitas modul di
 * sidebar profil Retail (dan tersembunyi untuk profil tanpa modul).
 */
class PromotionPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Flux modal memakai @teleport; matikan debug agar Livewire::test
        // tidak menolak render elemen multi-akar.
        config(['app.debug' => false]);
    }

    /** @return array{Tenant, User} */
    private function makeUser(FeatureProfile $profile = FeatureProfile::Retail): array
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
        $this->get('/promotions')->assertRedirect(route('login'));
    }

    public function test_page_renders_with_promotions(): void
    {
        [$tenant, $user] = $this->makeUser();
        Promotion::factory()->percent(10)->create([
            'tenant_id' => $tenant->id,
            'name' => 'Diskon Akhir Pekan',
        ]);

        $this->actingAs($user)
            ->get('/promotions')
            ->assertOk()
            ->assertSee('Diskon Akhir Pekan');
    }

    public function test_can_create_transaction_promo_via_volt(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        Livewire::test('promotions.index')
            ->call('openCreateModal')
            ->set('name', 'Beli Banyak Hemat')
            ->set('code', 'HEMAT10')
            ->set('discount_type', 'percent')
            ->set('discount_value', 10)
            ->set('scope', 'transaction')
            ->set('min_purchase_minor', 50_000)
            ->set('is_active', true)
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'name' => 'Beli Banyak Hemat',
            'code' => 'HEMAT10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'scope' => 'transaction',
            'min_purchase_minor' => 50_000,
            'is_active' => true,
        ]);
    }

    public function test_can_create_product_promo_with_selected_products(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::test('promotions.index')
            ->call('openCreateModal')
            ->set('name', 'Diskon Produk Pilihan')
            ->set('discount_type', 'nominal')
            ->set('discount_value', 5000)
            ->set('scope', 'product')
            ->set('selected_products', [$product->id])
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('promotion_products', [
            'product_id' => $product->id,
        ]);
    }

    public function test_product_scope_requires_selected_products(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        Livewire::test('promotions.index')
            ->call('openCreateModal')
            ->set('name', 'Tanpa Produk')
            ->set('scope', 'product')
            ->call('save')
            ->assertHasErrors(['selected_products']);
    }

    public function test_create_requires_name_and_valid_value(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        Livewire::test('promotions.index')
            ->call('openCreateModal')
            ->set('name', '')
            ->set('discount_value', 0)
            ->call('save')
            ->assertHasErrors(['name', 'discount_value']);
    }

    public function test_nominal_cap_stays_null_when_left_empty(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        Livewire::test('promotions.index')
            ->call('openCreateModal')
            ->set('name', 'Tanpa Cap')
            ->set('discount_type', 'nominal')
            ->set('discount_value', 2000)
            ->call('save')
            ->assertOk();

        // '' diubah jadi null (bukan 0) agar cap tidak menghapus diskon.
        $this->assertDatabaseHas('promotions', [
            'tenant_id' => $tenant->id,
            'name' => 'Tanpa Cap',
            'max_discount_minor' => null,
        ]);
    }

    public function test_can_edit_promo_via_volt(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        $promo = Promotion::factory()->percent(10)->create([
            'tenant_id' => $tenant->id,
            'name' => 'Lama',
        ]);

        Livewire::test('promotions.index')
            ->call('openEditModal', $promo->id)
            ->set('name', 'Baru')
            ->set('discount_value', 15)
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('promotions', [
            'id' => $promo->id,
            'name' => 'Baru',
            'discount_value' => 15,
        ]);
    }

    public function test_can_toggle_active_via_volt(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        $promo = Promotion::factory()->percent(10)->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        Livewire::test('promotions.index')
            ->call('toggleActive', $promo->id)
            ->assertOk();

        $this->assertDatabaseHas('promotions', [
            'id' => $promo->id,
            'is_active' => false,
        ]);
    }

    public function test_can_delete_promo_via_volt(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        $promo = Promotion::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::test('promotions.index')
            ->call('confirmDelete', $promo->id)
            ->assertOk();

        $this->assertSoftDeleted('promotions', ['id' => $promo->id]);
    }

    public function test_sidebar_shows_promosi_for_retail_profile(): void
    {
        [$tenant, $user] = $this->makeUser();
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Promosi');
    }

    public function test_sidebar_hides_promosi_when_module_absent(): void
    {
        // Profil Simple tidak punya modul promosi.
        [$tenant, $user] = $this->makeUser(FeatureProfile::Simple);
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Promosi');
    }
}
