<?php

namespace Tests\Feature\Menus;

use App\Actions\Menus\UpdateRecipe;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Aksi domain `UpdateRecipe` (Increment 40): replace atomik komposisi resep
 * menu + audit. HPP menu dihitung otomatis dari sum(qty x cost satuan bahan).
 *
 * @see UpdateRecipe
 */
class MenuRecipeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function basicSetup(): array
    {
        $tenant = Tenant::factory()->create();
        $menu = Menu::factory()->create(['tenant_id' => $tenant->id, 'price_minor' => 50_000]);
        $tepung = Ingredient::factory()->create(['tenant_id' => $tenant->id, 'cost_minor' => 2000]);
        $telur = Ingredient::factory()->create(['tenant_id' => $tenant->id, 'cost_minor' => 3000]);

        return [$tenant, $menu, $tepung, $telur];
    }

    /** @return array<string, mixed> */
    private function items(mixed ...$pairs): array
    {
        // Pasangan [ingredient, qty] diberikan bergantian.
        $items = [];
        for ($i = 0; $i < count($pairs); $i += 2) {
            $items[] = ['ingredient_id' => $pairs[$i]->id, 'quantity' => $pairs[$i + 1]];
        }

        return $items;
    }

    public function test_creates_recipe_lines_and_computes_cogs(): void
    {
        [$tenant, $menu, $tepung, $telur] = $this->basicSetup();

        $result = app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => $this->items($tepung, 2, $telur, 1),
        ]);

        $this->assertInstanceOf(Menu::class, $result);
        $this->assertCount(2, $result->recipeIngredients);
        $this->assertTrue($result->has_recipe);
        // HPP = (2 x 2000) + (1 x 3000)
        $this->assertSame(7000, $result->cogs_minor);
        $this->assertDatabaseHas('recipe_ingredients', [
            'menu_id' => $menu->id, 'ingredient_id' => $tepung->id, 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('recipe_ingredients', [
            'menu_id' => $menu->id, 'ingredient_id' => $telur->id, 'quantity' => 1,
        ]);
    }

    public function test_replaces_existing_composition_atomically(): void
    {
        [$tenant, $menu, $tepung, $telur] = $this->basicSetup();

        // Resep awal: 2x tepung.
        app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => $this->items($tepung, 2),
        ]);
        $this->assertSame(1, $menu->recipeIngredients()->count());

        // Diganti total: hanya 5x telur.
        $result = app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => $this->items($telur, 5),
        ]);

        $this->assertCount(1, $result->recipeIngredients);
        $this->assertSame($telur->id, $result->recipeIngredients->first()->ingredient_id);
        $this->assertSame(15_000, $result->cogs_minor);
        $this->assertDatabaseMissing('recipe_ingredients', [
            'menu_id' => $menu->id, 'ingredient_id' => $tepung->id,
        ]);
    }

    public function test_empty_items_clears_recipe(): void
    {
        [$tenant, $menu, $tepung] = $this->basicSetup();

        app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => $this->items($tepung, 2),
        ]);

        $result = app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => [],
        ]);

        $this->assertCount(0, $result->recipeIngredients);
        $this->assertFalse($result->has_recipe);
        $this->assertSame(0, $result->cogs_minor);
    }

    public function test_rejects_duplicate_ingredient(): void
    {
        [$tenant, $menu, $tepung] = $this->basicSetup();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplikat');

        app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => [
                ['ingredient_id' => $tepung->id, 'quantity' => 1],
                ['ingredient_id' => $tepung->id, 'quantity' => 2],
            ],
        ]);
    }

    public function test_rejects_non_positive_quantity(): void
    {
        [$tenant, $menu, $tepung] = $this->basicSetup();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lebih dari 0');

        app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => [['ingredient_id' => $tepung->id, 'quantity' => 0]],
        ]);
    }

    public function test_rejects_unknown_ingredient(): void
    {
        [$tenant, $menu] = $this->basicSetup();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => [['ingredient_id' => 999_999, 'quantity' => 1]],
        ]);
    }

    public function test_rejects_ingredient_of_another_tenant(): void
    {
        [$tenant, $menu] = $this->basicSetup();
        $foreign = Ingredient::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'items' => [['ingredient_id' => $foreign->id, 'quantity' => 1]],
        ]);
    }

    public function test_rejects_unknown_menu(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Menu tidak ditemukan');

        app(UpdateRecipe::class)->handle([
            'menu_id' => 999_999,
            'items' => [],
        ]);
    }

    public function test_records_audit_log(): void
    {
        [$tenant, $menu, $tepung, $telur] = $this->basicSetup();

        app(UpdateRecipe::class)->handle([
            'menu_id' => $menu->id,
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'items' => $this->items($tepung, 2, $telur, 1),
        ]);

        $log = AuditLog::query()
            ->where('action', AuditAction::RecipeUpdated->value)
            ->where('entity_id', $menu->uuid)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('menu', $log->entity_type);
        $this->assertSame(2, $log->metadata['items_count']);
        $this->assertSame(7000, $log->metadata['total_cogs_minor']);
    }
}
