<?php

namespace App\Support\Features;

use App\Enums\FeatureProfile;
use App\Enums\Module;
use App\Models\Tenant;

/**
 * Aturan profil fitur (FR-13; unit test §22 "Aturan profil fitur").
 *
 * Modul efektif sebuah tenant:
 *   1. bila `enabled_modules` terisi → daftar eksplisit itu (override
 *      per-tenant, mis. hasil toggle Owner);
 *   2. bila null/kosong → default dari `feature_profile`.
 *
 * Resolusi ini murni VISIBILITAS. Mematikan modul tidak menyentuh data, dan
 * mengaktifkan kembali memulihkan akses ke data yang sama (FR-13).
 */
final class FeatureProfileManager
{
    public function __construct(private readonly Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * Modul efektif tenant saat ini.
     *
     * @return list<Module>
     */
    public function modules(): array
    {
        $profile = $this->tenant->feature_profile instanceof FeatureProfile
            ? $this->tenant->feature_profile
            : (FeatureProfile::tryFrom($this->tenant->feature_profile) ?? FeatureProfile::Simple);

        return self::resolve($profile, $this->tenant->enabled_modules);
    }

    /**
     * Modul efektif untuk kombinasi profil + daftar eksplisit.
     *
     * @param  list<string>|null  $enabledModules
     * @return list<Module>
     */
    public static function resolve(FeatureProfile $profile, ?array $enabledModules): array
    {
        $names = ($enabledModules === null || $enabledModules === [])
            ? $profile->modules()
            : $enabledModules;

        $modules = [];

        foreach ($names as $name) {
            $module = Module::tryFrom($name);

            if ($module !== null && ! in_array($module, $modules, true)) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    public function isEnabled(Module $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    public function has(Module|string $module): bool
    {
        $module = $module instanceof Module ? $module : Module::tryFrom($module);

        return $module !== null && $this->isEnabled($module);
    }

    /**
     * Identifier string modul efektif (untuk disimpan/dibandingkan).
     *
     * @return list<string>
     */
    public function moduleNames(): array
    {
        return array_map(fn (Module $module) => $module->value, $this->modules());
    }
}
