<?php

namespace Tests\Feature\Features;

use App\Enums\FeatureProfile;
use App\Enums\Module;
use Tests\TestCase;

class ModuleCatalogTest extends TestCase
{
    public function test_module_catalog_values_and_labels(): void
    {
        $this->assertCount(16, Module::cases());

        foreach (Module::cases() as $module) {
            $this->assertNotEmpty($module->label());
        }

        $this->assertSame('reports_advanced', Module::ReportsAdvanced->value);
        $this->assertSame('Laporan lanjutan', Module::ReportsAdvanced->label());

        // Increment 40: bahan baku untuk resep F&B.
        $this->assertSame('ingredients', Module::Ingredients->value);
        $this->assertSame('Bahan Baku', Module::Ingredients->label());
    }

    public function test_simple_profile_hides_purchases_and_advanced_reports(): void
    {
        $modules = FeatureProfile::Simple->modules();

        $this->assertNotContains(Module::Purchases->value, $modules);
        $this->assertNotContains(Module::ReportsAdvanced->value, $modules);
        $this->assertContains(Module::Cashier->value, $modules);
        $this->assertContains(Module::Reports->value, $modules);
    }

    public function test_retail_profile_shows_stock_suppliers_and_purchases(): void
    {
        $modules = FeatureProfile::Retail->modules();

        $this->assertContains(Module::Stock->value, $modules);
        $this->assertContains(Module::Suppliers->value, $modules);
        $this->assertContains(Module::Purchases->value, $modules);
    }

    public function test_advanced_profile_contains_every_simple_module(): void
    {
        $this->assertSame([], array_diff(
            FeatureProfile::Simple->modules(),
            FeatureProfile::Advanced->modules(),
        ));

        $this->assertContains(Module::ReportsAdvanced->value, FeatureProfile::Advanced->modules());
    }

    public function test_food_beverage_profile_exposes_ingredients_module(): void
    {
        $modules = FeatureProfile::FoodBeverage->modules();

        $this->assertContains(Module::Menu->value, $modules);
        $this->assertContains(Module::Orders->value, $modules);
        $this->assertContains(Module::Ingredients->value, $modules);
    }

    public function test_all_profile_modules_are_known_catalog_values(): void
    {
        foreach (FeatureProfile::cases() as $profile) {
            foreach ($profile->modules() as $name) {
                $this->assertNotNull(
                    Module::tryFrom($name),
                    "Modul '{$name}' pada profil {$profile->value} tidak ada di katalog Module.",
                );
            }
        }
    }
}
