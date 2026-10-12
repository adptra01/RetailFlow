<?php

namespace App\Actions\Menus;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\RecipeIngredient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Update komposisi resep sebuah menu (modul F&B lanjutan).
 *
 * Atomik: baris resep lama dihapus dan diganti dalam satu transaksi, lalu
 * dicatat ke audit. Resep dihitung ulang lewat relasi (HPP menu =
 * sum(qty x cost satuan bahan)).
 */
class UpdateRecipe
{
    /**
     * @param  array{
     *     menu_id: int,
     *     user_id: int|null,
     *     device_id?: string|null,
     *     tenant_id?: string|null,
     *     items: list<array{ingredient_id: int, quantity: int, notes?: string|null}>,
     * }  $data
     */
    public function handle(array $data): Menu
    {
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        /** @var Menu|null $menu */
        $menu = Menu::query()->whereKey((int) $data['menu_id'])->first();

        if (! $menu) {
            throw new \InvalidArgumentException('Menu tidak ditemukan.');
        }

        $items = $this->normalizeItems($data['items'] ?? [], $tenantId);

        DB::transaction(function () use ($menu, $items, $tenantId) {
            RecipeIngredient::query()
                ->where('menu_id', $menu->id)
                ->forceDelete();

            foreach ($items as $item) {
                RecipeIngredient::create([
                    'menu_id' => $menu->id,
                    'ingredient_id' => $item['ingredient_id'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                    'tenant_id' => $tenantId,
                ]);
            }

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_id' => $data['user_id'] ?? null,
                'device_id' => $data['device_id'] ?? null,
                'action' => AuditAction::RecipeUpdated->value,
                'entity_type' => 'menu',
                'entity_id' => $menu->uuid,
                'metadata' => [
                    'menu_id' => $menu->uuid,
                    'items_count' => count($items),
                    'total_cogs_minor' => $this->totalCogs($items),
                ],
            ]);
        });

        return $menu->fresh()->load('recipeIngredients.ingredient');
    }

    /**
     * @return list<array{ingredient_id: int, quantity: int, notes: string|null}>
     */
    private function normalizeItems(mixed $rawItems, ?string $tenantId): array
    {
        if (! is_array($rawItems)) {
            throw new \InvalidArgumentException('Items resep harus berupa array.');
        }

        $normalized = [];
        $seen = [];

        foreach ($rawItems as $raw) {
            if (! is_array($raw) || ! isset($raw['ingredient_id'])) {
                throw new \InvalidArgumentException('Item resep tidak valid.');
            }

            $ingredientId = (int) $raw['ingredient_id'];
            $quantity = (int) ($raw['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw new \InvalidArgumentException('Kuantitas bahan harus lebih dari 0.');
            }

            if (isset($seen[$ingredientId])) {
                throw new \InvalidArgumentException('Bahan yang sama tidak boleh duplikat dalam satu resep.');
            }

            $ingredient = Ingredient::query()
                ->whereKey($ingredientId)
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->first();

            if (! $ingredient) {
                throw new \InvalidArgumentException("Bahan baku #{$ingredientId} tidak ditemukan.");
            }

            $seen[$ingredientId] = true;
            $normalized[] = [
                'ingredient_id' => $ingredientId,
                'quantity' => $quantity,
                'notes' => isset($raw['notes']) && is_string($raw['notes']) && $raw['notes'] !== ''
                    ? $raw['notes']
                    : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: int, notes: string|null}>  $items
     */
    private function totalCogs(array $items): int
    {
        $ingredients = Ingredient::query()
            ->whereIn('id', array_column($items, 'ingredient_id'))
            ->pluck('cost_minor', 'id');

        $total = 0;
        foreach ($items as $item) {
            $total += $item['quantity'] * (int) ($ingredients[$item['ingredient_id']] ?? 0);
        }

        return $total;
    }
}
