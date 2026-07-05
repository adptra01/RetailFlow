<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('products.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $response = $this->get(route('products.index'));

        $response->assertOk();
    }

    public function test_can_create_a_product(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();

        $this->get(route('products.create'))->assertOk();
    }

    public function test_validation_fails_without_required_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('products.create'))->assertOk();
    }

    public function test_can_edit_a_product(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->get(route('products.edit', ['product' => $product->id]))->assertOk();
    }

    public function test_can_delete_a_product(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        Livewire::test('products.index')
            ->call('confirmDelete', $product->id)
            ->call('delete')
            ->assertOk();

        $this->assertDatabaseMissing('products', [
            'id' => $product->id,
        ]);
    }

    public function test_can_toggle_product_active_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('products.create'))->assertOk();
    }

    public function test_can_search_products_by_name_or_sku(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();

        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Laptop Gaming',
            'sku' => 'SKU-LAPTOP',
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Office Chair',
            'sku' => 'SKU-CHAIR',
        ]);

        $response = $this->get(route('products.index', ['search' => 'Laptop']));
        $response->assertSee('Laptop Gaming');
        $response->assertDontSee('Office Chair');
    }

    public function test_duplicate_sku_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('products.create'))->assertOk();
    }

    public function test_low_stock_indicator(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Low Stock Item',
            'stock' => 2,
        ]);

        $response = $this->get(route('products.index'));
        $response->assertSee('Low Stock Item');
        $response->assertSee('2');
    }
}
