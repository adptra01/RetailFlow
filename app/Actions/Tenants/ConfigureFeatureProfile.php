<?php

namespace App\Actions\Tenants;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Enums\FeatureProfile;
use App\Enums\Module;
use App\Enums\Permission;
use App\Exceptions\Tenants\TenantNotFound;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Features\FeatureProfileManager;

/**
 * Use case konfigurasi profil fitur tenant (FR-13, use case infrastruktur
 * §10.4 "settings").
 *
 * Aturan: setiap perubahan hanya menyentuh KONFIGURASI (`feature_profile`,
 * `enabled_modules`, `business_type`, `ui_complexity`, `onboarding_state`) —
 * tidak pernah menghapus data. Mematikan modul lalu mengaktifkannya kembali
 * memulihkan akses ke data yang sama.
 *
 * Otorisasi: dipetakan ke izin administratif FR-12 `user.manage` (satu-satunya
 * izin administrasi tenant pada katalog; dapat dipersempit bila izin khusus
 * `settings.manage` ditambahkan kelak). Semua perubahan dicatat audit (FR-12).
 */
class ConfigureFeatureProfile
{
    private readonly AuditRecorder $audit;

    public function __construct(
        private readonly string $tenantId,
        ?AuditRecorder $audit = null,
    ) {
        $this->audit = $audit ?? new AuditRecorder($this->tenantId);
    }

    /**
     * Terapkan profil fitur: set `feature_profile` dan setel ulang
     * `enabled_modules` ke default profil. Data tidak dihapus.
     *
     * @throws ActionForbidden|TenantNotFound
     */
    public function applyProfile(User $actor, FeatureProfile $profile, ?string $reason = null): Tenant
    {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        $tenant = $this->tenant();

        $tenant->update([
            'feature_profile' => $profile->value,
            'enabled_modules' => array_values($profile->modules()),
        ]);

        $this->audit->record(
            AuditAction::FeatureProfileChanged,
            $actor,
            entityType: 'tenant',
            entityId: $tenant->id,
            reason: $reason,
            metadata: ['profile' => $profile->value, 'modules' => $profile->modules()],
        );

        return $tenant;
    }

    /**
     * Aktifkan satu modul (menambah ke `enabled_modules`). Idempoten: bila
     * sudah aktif, tidak ada perubahan & tidak ada jejak audit baru.
     *
     * @throws ActionForbidden|TenantNotFound
     */
    public function enableModule(User $actor, Module $module, ?string $reason = null): Tenant
    {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        $tenant = $this->tenant();
        $current = FeatureProfileManager::for($tenant)->moduleNames();

        if (in_array($module->value, $current, true)) {
            return $tenant;
        }

        $current[] = $module->value;
        $tenant->update(['enabled_modules' => array_values($current)]);

        $this->audit->record(
            AuditAction::ModuleEnabled,
            $actor,
            entityType: 'tenant',
            entityId: $tenant->id,
            reason: $reason,
            metadata: ['module' => $module->value],
        );

        return $tenant;
    }

    /**
     * Matikan satu modul (menghapus dari `enabled_modules`). Data terkait
     * TIDAK dihapus; mengaktifkan kembali memulihkan akses. Idempoten.
     *
     * @throws ActionForbidden|TenantNotFound
     */
    public function disableModule(User $actor, Module $module, ?string $reason = null): Tenant
    {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        $tenant = $this->tenant();
        $current = FeatureProfileManager::for($tenant)->moduleNames();

        if (! in_array($module->value, $current, true)) {
            return $tenant;
        }

        $remaining = array_values(array_filter(
            $current,
            fn (string $name) => $name !== $module->value,
        ));

        $tenant->update(['enabled_modules' => $remaining]);

        $this->audit->record(
            AuditAction::ModuleDisabled,
            $actor,
            entityType: 'tenant',
            entityId: $tenant->id,
            reason: $reason,
            metadata: ['module' => $module->value],
        );

        return $tenant;
    }

    /**
     * Ubah konfigurasi umum tenant (business_type / ui_complexity /
     * onboarding_state). Hanya field yang diberikan yang diubah.
     *
     * @throws ActionForbidden|TenantNotFound
     */
    public function configure(
        User $actor,
        ?string $businessType = null,
        ?string $uiComplexity = null,
        ?string $onboardingState = null,
        ?string $reason = null,
    ): Tenant {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        $tenant = $this->tenant();
        $changes = [];

        if ($businessType !== null && $businessType !== $tenant->business_type) {
            $changes['business_type'] = $businessType;
        }
        if ($uiComplexity !== null && $uiComplexity !== $tenant->ui_complexity) {
            $changes['ui_complexity'] = $uiComplexity;
        }
        if ($onboardingState !== null && $onboardingState !== $tenant->onboarding_state) {
            $changes['onboarding_state'] = $onboardingState;
        }

        if ($changes === []) {
            return $tenant;
        }

        $tenant->update($changes);

        $this->audit->record(
            AuditAction::TenantConfigured,
            $actor,
            entityType: 'tenant',
            entityId: $tenant->id,
            reason: $reason,
            metadata: $changes,
        );

        return $tenant;
    }

    /**
     * @throws TenantNotFound
     */
    private function tenant(): Tenant
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            throw new TenantNotFound("Tenant {$this->tenantId} tidak ditemukan.");
        }

        return $tenant;
    }
}
