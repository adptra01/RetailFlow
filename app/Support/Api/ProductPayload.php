<?php

namespace App\Support\Api;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\Money\Money;

/**
 * Bentuk kiriman produk untuk klien perangkat (§18 `GET /api/v1/products`).
 *
 * Aturan §17.1/§18: harga dikirim sebagai **integer minor unit** (bukan
 * pecahan) dan server adalah sumber harga yang sah — klien tidak boleh
 * mengirim balik harga yang ia percaya.
 *
 * Harga beli (`buy_price`, `purchase_price`) sengaja TIDAK dikirim: itu
 * informasi margin, bukan kebutuhan kasir.
 */
final class ProductPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Product $product, bool $withUnits = false): array
    {
        $baseUnit = $product->units->firstWhere('is_base', true) ?? $product->units->first();

        $payload = [
            'id' => $product->getKey(),
            'uuid' => $product->uuid,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'slug' => $product->slug,
            'is_active' => (bool) $product->is_active,
            'is_unlimited_stock' => (bool) $product->is_unlimited_stock,
            'price_minor' => Money::fromMajor($product->price)->minor(),
            'stock' => (int) $product->stock,
            'min_stock' => (int) $product->min_stock,
            'category' => $product->category === null ? null : [
                'id' => $product->category->getKey(),
                'name' => $product->category->name,
            ],
            'brand' => $product->brand === null ? null : [
                'id' => $product->brand->getKey(),
                'name' => $product->brand->name,
            ],
            'base_unit' => $baseUnit === null ? null : self::unit($baseUnit),
            'version' => (int) $product->version,
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];

        if ($withUnits) {
            $payload['units'] = $product->units
                ->map(fn ($unit) => self::unit($unit))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function unit(ProductUnit $unit): array
    {
        return [
            'id' => $unit->getKey(),
            'name' => $unit->name,
            'abbreviation' => $unit->abbreviation,
            'conversion_factor' => (int) $unit->conversion_factor,
            'is_base' => (bool) $unit->is_base,
            'is_active' => (bool) $unit->is_active,
            'price_minor' => Money::fromMajor($unit->price)->minor(),
        ];
    }
}
