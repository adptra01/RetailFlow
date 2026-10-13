<?php

namespace App\Support\Api;

use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menerjemahkan kontrak checkout §18 menjadi data `CreateSale`, dengan
 * **harga dihitung server**.
 *
 * §18: "Nilai harga yang dikirim client tidak boleh dipercaya sebagai sumber
 * final. Server atau domain service harus menghitung ulang menggunakan aturan
 * dan harga yang sah." Karena itu:
 *
 *  - `items[].product_id` (UUID atau id) dan `quantity` adalah satu-satunya
 *    masukan yang dipercaya; `unit_price_minor` SELALU diambil dari master
 *    (harga satuan bila dikirim, jika tidak harga satuan dasar produk);
 *  - produk harus milik tenant pengguna dan aktif (`is_active`) — produk
 *    nonaktif tidak dijual (FR-02);
 *  - `payments[].method` divalidasi terhadap katalog `PaymentMethod` dan
 *    "mixed" tidak boleh dipilih langsung (FR-05);
 *  - `discount` (§18: `{type, value}`) dipetakan ke diskon nominal/persen;
 *  - `allow_negative_stock` TIDAK pernah diambil dari klien: itu kebijakan
 *    toko (`pos.inventory.allow_negative_stock`, §11.4), bukan permintaan
 *    perangkat.
 */
final class SaleCheckoutMapper
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function map(array $validated, User $user, Tenant $tenant): array
    {
        $items = $this->resolveItems($validated['items'], $tenant->id);
        $discount = $this->resolveDiscount($validated['discount'] ?? null);

        $data = [
            'user_id' => $user->getKey(),
            'tenant_id' => $tenant->id,
            'items' => $items,
            'discount_minor' => $discount['discount_minor'],
            'discount_percent' => $discount['discount_percent'],
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'customer_name' => $validated['customer_name'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'location_id' => $validated['location_id'] ?? null,
            'device_id' => $validated['device_id'] ?? null,
            // §11.1: sisi server tidak memantulkan penjualan kembali ke outbox.
            'enqueue_for_sync' => false,
        ];

        if (isset($validated['due_at'])) {
            $data['due_at'] = $validated['due_at'];
        }

        if (isset($validated['due_days'])) {
            $data['due_days'] = (int) $validated['due_days'];
        }

        $payments = $this->resolvePayments($validated);

        if ($payments !== null) {
            $data['payments'] = $payments;
        } else {
            $data['payment_method'] = $validated['payment_method'] ?? PaymentMethod::Cash->value;
            $data['paid_minor'] = (int) ($validated['paid_minor'] ?? 0);
        }

        return $data;
    }

    /**
     * Harga satuan otoritatif per baris (§18, FR-04).
     *
     * @param  list<array<string, mixed>>  $rawItems
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function resolveItems(array $rawItems, string $tenantId): array
    {
        $products = $this->loadProducts($rawItems, $tenantId);
        $resolved = [];

        foreach ($rawItems as $index => $raw) {
            $key = (string) $raw['product_id'];
            $product = $products[$this->normalizeKey($key)] ?? null;

            if ($product === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => "Produk {$key} tidak ditemukan pada toko ini.",
                ]);
            }

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => "Produk {$product->name} sudah tidak aktif dan tidak dapat dijual.",
                ]);
            }

            $unit = $this->resolveUnit($product, $raw['product_unit_id'] ?? null, $index);

            $unitPrice = $unit !== null
                ? Money::fromMajor($unit->price)
                : Money::fromMajor($product->price);

            $resolved[] = [
                'product_id' => $product->getKey(),
                'quantity' => (int) $raw['quantity'],
                'unit_price_minor' => $unitPrice->minor(),
                'product_unit_id' => $unit?->getKey(),
                'unit_name' => $unit?->name,
                'conversion_factor' => $unit !== null ? (float) $unit->conversion_factor : 1.0,
                'discount_minor' => (int) ($raw['discount_minor'] ?? 0),
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array<string, mixed>>  $rawItems
     * @return array<string, Product>
     */
    private function loadProducts(array $rawItems, string $tenantId): array
    {
        $uuidKeys = [];
        $idKeys = [];

        foreach ($rawItems as $raw) {
            $key = (string) $raw['product_id'];

            if (Str::isUuid($key)) {
                $uuidKeys[] = $key;
            } else {
                $idKeys[] = (int) $key;
            }
        }

        $products = Product::query()
            ->withoutGlobalScope('tenant')
            ->with('units')
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($uuidKeys, $idKeys): void {
                if ($uuidKeys !== []) {
                    $query->orWhereIn('uuid', $uuidKeys);
                }

                if ($idKeys !== []) {
                    $query->orWhereIn('id', $idKeys);
                }
            })
            ->get();

        $indexed = [];

        foreach ($products as $product) {
            $indexed['id:'.$product->getKey()] = $product;

            if ($product->uuid !== null) {
                $indexed['uuid:'.$product->uuid] = $product;
            }
        }

        return $indexed;
    }

    private function normalizeKey(string $key): string
    {
        return Str::isUuid($key) ? 'uuid:'.$key : 'id:'.(int) $key;
    }

    /**
     * Satuan yang dipilih klien harus benar-benar milik produk itu (§12).
     */
    private function resolveUnit(Product $product, mixed $unitId, int $index): ?ProductUnit
    {
        /** @var ProductUnit|null $base */
        $base = $product->units->firstWhere('is_base', true) ?? $product->units->first();

        if ($unitId === null || $unitId === '') {
            return $base;
        }

        $unit = $product->units->firstWhere('id', (int) $unitId);

        if ($unit === null) {
            throw ValidationException::withMessages([
                "items.{$index}.product_unit_id" => "Satuan tidak ditemukan pada produk {$product->name}.",
            ]);
        }

        if (! $unit->is_active) {
            throw ValidationException::withMessages([
                "items.{$index}.product_unit_id" => "Satuan {$unit->name} sudah tidak aktif.",
            ]);
        }

        return $unit;
    }

    /**
     * `{type: "fixed"|"percent", value: number}` (§18). Nominal dalam minor
     * unit; persen dalam satuan persen (10 = 10%).
     *
     * @param  array<string, mixed>|null  $discount
     * @return array{discount_minor: int, discount_percent: float}
     */
    private function resolveDiscount(?array $discount): array
    {
        if ($discount === null) {
            return ['discount_minor' => 0, 'discount_percent' => 0.0];
        }

        $value = (float) $discount['value'];

        return ($discount['type'] ?? 'fixed') === 'percent'
            ? ['discount_minor' => 0, 'discount_percent' => $value]
            : ['discount_minor' => (int) round($value), 'discount_percent' => 0.0];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{payment_method: string, amount_minor: int, reference: string|null, notes: string|null}>|null
     */
    private function resolvePayments(array $validated): ?array
    {
        $payments = $validated['payments'] ?? null;

        if (! is_array($payments) || $payments === []) {
            return null;
        }

        $tenders = [];

        foreach ($payments as $payment) {
            $tenders[] = [
                'payment_method' => (string) $payment['method'],
                'amount_minor' => (int) $payment['amount_minor'],
                'reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? null,
            ];
        }

        return $tenders;
    }
}
