<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\AdvanceOrderStatus;
use App\Enums\AuditAction;
use App\Exceptions\Orders\InvalidOrderTransition;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecipeIngredient;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Aksi domain `AdvanceOrderStatus` (Increment 40): mesin status pesanan F&B
 * dengan efek samping stok bahan baku.
 *
 * - `pending → preparing` : bahan baku di-deduct sesuai resep x qty item.
 * - `preparing/ready → cancelled` : stok bahan dikembalikan.
 * - Transisi lain hanya mengganti status; stok tidak disentuh.
 */
class AdvanceOrderStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Order `pending` berisi 1 item menu dengan resep:
     * 3x Tepung (cost 1.000, stok 100) + 1x Gula (cost 2.000, stok 50).
     *
     * @return array{Tenant, Order, Ingredient, Ingredient}
     */
    private function makeOrderWithRecipe(int $menuQty = 1): array
    {
        $tenant = Tenant::factory()->create();
        $menu = Menu::factory()->create(['tenant_id' => $tenant->id, 'price_minor' => 25_000]);
        $tepung = Ingredient::factory()->create([
            'tenant_id' => $tenant->id, 'stock_qty' => 100, 'cost_minor' => 1000,
        ]);
        $gula = Ingredient::factory()->create([
            'tenant_id' => $tenant->id, 'stock_qty' => 50, 'cost_minor' => 2000,
        ]);

        RecipeIngredient::create([
            'menu_id' => $menu->id, 'ingredient_id' => $tepung->id, 'quantity' => 3, 'tenant_id' => $tenant->id,
        ]);
        RecipeIngredient::create([
            'menu_id' => $menu->id, 'ingredient_id' => $gula->id, 'quantity' => 1, 'tenant_id' => $tenant->id,
        ]);

        $order = Order::factory()->pending()->create(['tenant_id' => $tenant->id]);
        OrderItem::factory()->forOrder($order)->create([
            'tenant_id' => $tenant->id, 'menu_id' => $menu->id, 'quantity' => $menuQty,
        ]);

        return [$tenant, $order, $tepung, $gula];
    }

    /** @return array<string, mixed> */
    private function advance(Order $order, string $status, ?Tenant $tenant = null): array
    {
        return app(AdvanceOrderStatus::class)->handle([
            'order_id' => $order->id,
            'status' => $status,
            'tenant_id' => $tenant?->id,
        ]);
    }

    public function test_pending_to_preparing_deducts_recipe_ingredients(): void
    {
        [$tenant, $order, $tepung, $gula] = $this->makeOrderWithRecipe(menuQty: 2);

        $result = $this->advance($order, 'preparing', $tenant);

        $this->assertSame('preparing', $result['order']->status);
        $this->assertSame(['pending', 'preparing'], [$result['status_from'], $result['status_to']]);
        $this->assertTrue($result['inventory_adjusted']);
        $this->assertCount(2, $result['lines']);
        // Tepung: 100 - (3 x 2) = 94; Gula: 50 - (1 x 2) = 48.
        $this->assertSame(94, $tepung->fresh()->stock_qty);
        $this->assertSame(48, $gula->fresh()->stock_qty);
    }

    public function test_preparing_to_ready_does_not_deduct_again(): void
    {
        [$tenant, $order, $tepung, $gula] = $this->makeOrderWithRecipe();
        $this->advance($order, 'preparing', $tenant);
        $this->assertSame(97, $tepung->fresh()->stock_qty);

        $result = $this->advance($order, 'ready', $tenant);

        $this->assertSame('ready', $result['order']->status);
        $this->assertFalse($result['inventory_adjusted']);
        $this->assertSame([], $result['lines']);
        $this->assertSame(97, $tepung->fresh()->stock_qty);
        $this->assertSame(49, $gula->fresh()->stock_qty);
    }

    public function test_ready_to_served_and_served_to_paid_touch_no_stock(): void
    {
        [$tenant, $order, $tepung, $gula] = $this->makeOrderWithRecipe();
        $this->advance($order, 'preparing', $tenant);
        $this->advance($order, 'ready', $tenant);

        $served = $this->advance($order, 'served', $tenant);
        $this->assertFalse($served['inventory_adjusted']);

        $paid = $this->advance($order, 'paid', $tenant);
        $this->assertSame('paid', $paid['order']->status);
        $this->assertFalse($paid['inventory_adjusted']);
        $this->assertSame(97, $tepung->fresh()->stock_qty);
    }

    public function test_cancelled_after_preparing_restores_ingredients(): void
    {
        [$tenant, $order, $tepung, $gula] = $this->makeOrderWithRecipe();
        $this->advance($order, 'preparing', $tenant);

        $result = $this->advance($order, 'cancelled', $tenant);

        $this->assertSame('cancelled', $result['order']->status);
        $this->assertTrue($result['inventory_adjusted']);
        $this->assertSame(100, $tepung->fresh()->stock_qty);
        $this->assertSame(50, $gula->fresh()->stock_qty);
    }

    public function test_cancelled_after_ready_restores_ingredients(): void
    {
        [$tenant, $order, $tepung, $gula] = $this->makeOrderWithRecipe();
        $this->advance($order, 'preparing', $tenant);
        $this->advance($order, 'ready', $tenant);

        $result = $this->advance($order, 'cancelled', $tenant);

        $this->assertSame('cancelled', $result['order']->status);
        $this->assertTrue($result['inventory_adjusted']);
        $this->assertSame(100, $tepung->fresh()->stock_qty);
        $this->assertSame(50, $gula->fresh()->stock_qty);
    }

    public function test_cancelled_from_pending_does_not_touch_stock(): void
    {
        [$tenant, $order, $tepung, $gula] = $this->makeOrderWithRecipe();

        $result = $this->advance($order, 'cancelled', $tenant);

        $this->assertSame('cancelled', $result['order']->status);
        $this->assertFalse($result['inventory_adjusted']);
        $this->assertSame(100, $tepung->fresh()->stock_qty);
        $this->assertSame(50, $gula->fresh()->stock_qty);
    }

    public function test_invalid_transition_throws_and_keeps_status(): void
    {
        [$tenant, $order, $tepung, $gula] = $this->makeOrderWithRecipe();

        try {
            $this->advance($order, 'served', $tenant);
            $this->fail('InvalidOrderTransition seharusnya dilempar.');
        } catch (InvalidOrderTransition $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
            $this->assertStringContainsString('served', $e->getMessage());
        }

        // Tidak ada efek samping: status & stok tetap.
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(100, $tepung->fresh()->stock_qty);
        $this->assertSame(50, $gula->fresh()->stock_qty);
    }

    public function test_unknown_order_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->advance(new Order, 'preparing');
    }

    public function test_order_without_recipe_does_not_adjust_inventory(): void
    {
        $tenant = Tenant::factory()->create();
        $menu = Menu::factory()->create(['tenant_id' => $tenant->id]);
        $order = Order::factory()->pending()->create(['tenant_id' => $tenant->id]);
        OrderItem::factory()->forOrder($order)->create([
            'tenant_id' => $tenant->id, 'menu_id' => $menu->id, 'quantity' => 1,
        ]);

        $result = $this->advance($order, 'preparing', $tenant);

        $this->assertSame('preparing', $result['order']->status);
        $this->assertFalse($result['inventory_adjusted']);
        $this->assertSame([], $result['lines']);
    }

    public function test_deduct_allowed_even_if_stock_goes_negative(): void
    {
        $tenant = Tenant::factory()->create();
        $menu = Menu::factory()->create(['tenant_id' => $tenant->id]);
        $tepung = Ingredient::factory()->create(['tenant_id' => $tenant->id, 'stock_qty' => 2]);
        RecipeIngredient::create([
            'menu_id' => $menu->id, 'ingredient_id' => $tepung->id, 'quantity' => 3, 'tenant_id' => $tenant->id,
        ]);
        $order = Order::factory()->pending()->create(['tenant_id' => $tenant->id]);
        OrderItem::factory()->forOrder($order)->create([
            'tenant_id' => $tenant->id, 'menu_id' => $menu->id, 'quantity' => 1,
        ]);

        $result = $this->advance($order, 'preparing', $tenant);

        $this->assertTrue($result['inventory_adjusted']);
        $this->assertSame(-1, $tepung->fresh()->stock_qty);
    }

    public function test_transition_writes_audit_log(): void
    {
        [$tenant, $order] = $this->makeOrderWithRecipe();

        $this->advance($order, 'preparing', $tenant);

        $log = AuditLog::query()
            ->where('action', AuditAction::OrderStatusChanged->value)
            ->where('entity_id', $order->uuid)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('order', $log->entity_type);
        $this->assertSame($order->order_number, $log->metadata['order_number']);
        $this->assertSame('pending', $log->metadata['status_from']);
        $this->assertSame('preparing', $log->metadata['status_to']);
        $this->assertTrue($log->metadata['inventory_adjusted']);
        $this->assertSame(2, $log->metadata['lines_count']);
    }
}
