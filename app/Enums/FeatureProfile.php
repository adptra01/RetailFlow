<?php

namespace App\Enums;

/**
 * Business feature profiles (plan FR-13).
 *
 * A profile controls configuration and menu visibility only. It must never
 * replace authorization: permissions are always enforced separately.
 */
enum FeatureProfile: string
{
    case Simple = 'simple';
    case Retail = 'retail';
    case FoodBeverage = 'food_beverage';
    case CreditSales = 'credit_sales';
    case Advanced = 'advanced';

    /**
     * Modules exposed for this profile. Turning a module off never deletes
     * data; re-enabling restores access to it (FR-13).
     *
     * @return list<string>
     */
    public function modules(): array
    {
        return match ($this) {
            self::Simple => ['cashier', 'products', 'history', 'reports'],
            self::Retail => ['cashier', 'products', 'history', 'stock', 'purchases', 'suppliers', 'reports'],
            self::FoodBeverage => ['cashier', 'menu', 'orders', 'ingredients', 'reports'],
            self::CreditSales => ['cashier', 'customers', 'receivables', 'reports'],
            self::Advanced => [
                'cashier', 'products', 'history', 'stock', 'purchases', 'suppliers', 'customers',
                'receivables', 'promotions', 'reports', 'reports_advanced', 'menu', 'orders', 'ingredients', 'audit', 'sync',
            ],
        };
    }
}
