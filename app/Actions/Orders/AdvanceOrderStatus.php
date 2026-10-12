<?php

namespace App\Actions\Orders;

use App\Enums\AuditAction;
use App\Exceptions\Orders\InvalidOrderTransition;
use App\Models\AuditLog;
use App\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Transisi status pesanan (F&B) dengan efek samping stok bahan baku.
 *
 * - `pending → preparing`  : bahan baku di-deduct (sesuai resep x qty item).
 * - `preparing/ready → cancelled` : bahan baku dikembalikan (restore).
 * - Transisi lain hanya mengubah label status; tidak menyentuh stok.
 *
 * Atomic + idempoten secara alami: state machine hanya mengizinkan satu
 * transisi per status, sehingga double-deduct tidak mungkin terjadi.
 */
class AdvanceOrderStatus
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'pending' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['served', 'cancelled'],
        'served' => ['paid'],
    ];

    /**
     * @param  array{
     *     order_id: int,
     *     status: string,
     *     user_id?: int|null,
     *     device_id?: string|null,
     *     tenant_id?: string|null,
     * }  $data
     * @return array<string, mixed>
     */
    public function handle(array $data): array
    {
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        return DB::transaction(function () use ($data, $tenantId) {
            /** @var Order|null $order */
            $order = Order::query()
                ->with(['items.menu.recipeIngredients.ingredient'])
                ->whereKey((int) $data['order_id'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new \InvalidArgumentException('Pesanan tidak ditemukan.');
            }

            $to = (string) $data['status'];
            $from = $order->status;

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new InvalidOrderTransition(
                    "Transisi status tidak sah: {$from} → {$to}."
                );
            }

            $lines = $this->adjustInventory($order, $from, $to, $tenantId);

            $order->update(['status' => $to]);

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_id' => $data['user_id'] ?? null,
                'device_id' => $data['device_id'] ?? null,
                'action' => AuditAction::OrderStatusChanged->value,
                'entity_type' => 'order',
                'entity_id' => $order->uuid,
                'metadata' => [
                    'order_number' => $order->order_number,
                    'status_from' => $from,
                    'status_to' => $to,
                    'inventory_adjusted' => count($lines) > 0,
                    'lines_count' => count($lines),
                ],
            ]);

            return [
                'order' => $order->fresh(),
                'status_from' => $from,
                'status_to' => $to,
                'inventory_adjusted' => count($lines) > 0,
                'lines' => $lines,
            ];
        });
    }

    /**
     * Deduct/restore stok bahan sesuai arah transisi.
     *
     * @return list<array<string, mixed>>
     */
    private function adjustInventory(Order $order, string $from, string $to, ?string $tenantId): array
    {
        $direction = match (true) {
            $from === 'pending' && $to === 'preparing' => -1,
            $to === 'cancelled' && in_array($from, ['preparing', 'ready'], true) => 1,
            default => 0,
        };

        if ($direction === 0) {
            return [];
        }

        $lines = [];

        foreach ($order->items as $item) {
            if (! $item->menu_id || ! $item->menu) {
                continue;
            }

            foreach ($item->menu->recipeIngredients as $line) {
                $ingredient = $line->ingredient;

                if (! $ingredient) {
                    continue;
                }

                $delta = $direction * ($line->quantity * $item->quantity);

                if ($delta === 0) {
                    continue;
                }

                $before = $ingredient->stock_qty;
                $ingredient->increment('stock_qty', $delta);

                $lines[] = [
                    'ingredient_id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->unit,
                    'before' => $before,
                    'after' => $ingredient->stock_qty,
                    'delta' => $delta,
                ];
            }
        }

        return $lines;
    }
}
