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

        Livewire::test('products.product-create-form')
            ->set('category_id', $category->id)
            ->set('name', 'Smartphone')
            ->set('slug', 'smartphone')
            ->set('sku', 'SKU-TEST')
            ->set('price', 5000000)
            ->set('stock', 10)
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('products', [
            'name' => 'Smartphone',
            'slug' => 'smartphone',
            'category_id' => $category->id,
            'price' => 5000000,
            'stock' => 10,
        ]);
    }

    public function test_validation_fails_without_required_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('products.product-create-form')
            ->call('save')
            ->assertHasErrors(['category_id', 'name', 'slug', 'price', 'stock']);
    }

    public function test_can_edit_a_product(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $newCategory = Category::factory()->create();

        Livewire::test('products.product-edit-form', ['productId' => $product->id])
            ->set('category_id', $newCategory->id)
            ->set('name', 'Updated Phone')
            ->set('slug', 'updated-phone')
            ->set('price', 6000000)
            ->set('stock', 20)
            ->call('update')
            ->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'category_id' => $newCategory->id,
            'name' => 'Updated Phone',
            'price' => 6000000,
            'stock' => 20,
        ]);
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

        $category = Category::factory()->create();

        Livewire::test('products.product-create-form')
            ->set('category_id', $category->id)
            ->set('name', 'Test Product')
            ->set('slug', 'test-product')
            ->set('sku', 'SKU-ACTIVE')
            ->set('price', 10000)
            ->set('stock', 5)
            ->set('is_active', false)
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('products', [
            'name' => 'Test Product',
            'is_active' => false,
        ]);
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

        $category = Category::factory()->create();
        Product::factory()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-EXISTING',
        ]);

        Livewire::test('products.product-create-form')
            ->set('category_id', $category->id)
            ->set('name', 'New Product')
            ->set('slug', 'new-product')
            ->set('sku', 'SKU-EXISTING')
            ->set('price', 10000)
            ->set('stock', 5)
            ->call('save')
            ->assertHasErrors('sku');
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
