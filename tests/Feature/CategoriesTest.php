<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Flux modals use @teleport which creates multiple root elements
        // in the rendered Livewire component. Disable debug mode to skip
        // the multiple root element detection during component testing.
        config(['app.debug' => false]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('categories.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $response = $this->get(route('categories.index'));

        $response->assertOk();
    }

    public function test_can_create_a_category(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('categories.index')
            ->set('name', 'Electronics')
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('categories', [
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
    }

    public function test_validation_fails_without_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('categories.index')
            ->set('slug', 'test')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_duplicate_slug_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Category::factory()->create(['slug' => 'existing-slug']);

        Livewire::test('categories.index')
            ->set('name', 'Test')
            ->set('slug', 'existing-slug')
            ->call('save')
            ->assertHasErrors('slug');
    }

    public function test_can_edit_a_category(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();

        Livewire::test('categories.index')
            ->call('edit', $category->id)
            ->set('name', 'Updated Name')
            ->set('slug', 'updated-name')
            ->call('update')
            ->assertOk();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Updated Name',
            'slug' => 'updated-name',
        ]);
    }

    public function test_can_delete_a_category(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();

        Livewire::test('categories.index')
            ->call('confirmDelete', $category->id)
            ->call('delete')
            ->assertOk();

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_can_search_categories(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Category::factory()->create(['name' => 'Electronics']);
        Category::factory()->create(['name' => 'Furniture']);

        $response = $this->get(route('categories.index', ['search' => 'Elect']));
        $response->assertSee('Electronics');
        $response->assertDontSee('Furniture');
    }

    public function test_can_sort_categories_by_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Category::factory()->create(['name' => 'B']);
        Category::factory()->create(['name' => 'A']);
        Category::factory()->create(['name' => 'C']);

        $response = $this->get(route('categories.index', ['sortBy' => 'name', 'sortDirection' => 'asc']));
        $response->assertSeeInOrder(['A', 'B', 'C']);
    }
}
