<?php

namespace App\Actions\Users;

use App\Audit\AuditRecorder;
use App\Concerns\PasswordValidationRules;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\Users\LastOwnerProtected;
use App\Exceptions\Users\UserNotFound;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Use case pengelolaan pengguna (FR-12, izin `user.manage`).
 *
 * Manajemen pengguna bersifat per-tenant dan WAJIB diaudit: semua operasi
 * memerlukan aktor dengan izin `user.manage`, dan akun yang dinonaktifkan
 * otomatis ditolak oleh `ActionAuthorizer` (tidak bisa melakukan operasi
 * sensitif apa pun). Setiap operasi yang BERHASIL dicatat ke `audit_logs`
 * (entity = user, entity_id = UUID sync identity); operasi yang gagal
 * (validasi, otorisasi, last-owner) tidak menulis jejak.
 *
 * Aturan keamanan: owner terakhir sebuah tenant tidak dapat dinonaktifkan
 * atau diturunkan perannya — mencegah toko kehilangan akses administrasi.
 */
class ManageUser
{
    use PasswordValidationRules;

    private readonly AuditRecorder $audit;

    public function __construct(
        private readonly ?string $tenantId = null,
        ?AuditRecorder $audit = null,
    ) {
        $this->audit = $audit ?? new AuditRecorder($this->tenantId);
    }

    /**
     * Buat pengguna baru dalam tenant (aktif, dengan peran opsional).
     *
     * @param  array<string, mixed>  $input  name, email, password, password_confirmation
     *
     * @throws ActionForbidden|ValidationException|LastOwnerProtected
     */
    public function create(User $actor, array $input, ?Role $role = null): User
    {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'tenant_id' => $this->resolveTenantId($actor),
            'is_active' => true,
        ]);

        if ($role !== null) {
            $user->assignRole($role->value);
        }

        $this->audit->record(
            AuditAction::UserCreated,
            $actor,
            entityType: 'user',
            entityId: $user->uuid,
            metadata: ['role' => $role?->value],
        );

        return $user;
    }

    /**
     * Ubah profil pengguna (nama/email).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ActionForbidden|ValidationException|UserNotFound
     */
    public function updateProfile(User $actor, int $userId, array $input): User
    {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        $user = $this->findInTenant($this->resolveTenantId($actor), $userId);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ])->validate();

        $user->update([
            'name' => $input['name'],
            'email' => $input['email'],
        ]);

        $this->audit->record(
            AuditAction::UserUpdated,
            $actor,
            entityType: 'user',
            entityId: $user->uuid,
        );

        return $user;
    }

    /**
     * Aktifkan/nonaktifkan pengguna. Owner terakhir tidak dapat
     * dinonaktifkan (LastOwnerProtected).
     *
     * @throws ActionForbidden|UserNotFound|LastOwnerProtected
     */
    public function setActive(User $actor, int $userId, bool $active, ?string $reason = null): User
    {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        $tenantId = $this->resolveTenantId($actor);
        $user = $this->findInTenant($tenantId, $userId);

        if (! $active && $user->is_active && $user->hasRole(Role::Owner->value)) {
            $this->assertNotLastOwner($tenantId);
        }

        $user->update(['is_active' => $active]);

        $this->audit->record(
            $active ? AuditAction::UserReactivated : AuditAction::UserDeactivated,
            $actor,
            entityType: 'user',
            entityId: $user->uuid,
            reason: $reason,
        );

        return $user;
    }

    /**
     * Tetapkan peran pengguna (menggantikan peran lama). Owner terakhir
     * tidak dapat diturunkan perannya (LastOwnerProtected).
     *
     * @throws ActionForbidden|UserNotFound|LastOwnerProtected
     */
    public function assignRole(User $actor, int $userId, ?Role $role, ?string $reason = null): User
    {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        $tenantId = $this->resolveTenantId($actor);
        $user = $this->findInTenant($tenantId, $userId);

        $wasOwner = $user->hasRole(Role::Owner->value);
        $losesOwnership = $wasOwner && ($role === null || ! $role->isOwner());

        if ($losesOwnership && $user->is_active) {
            $this->assertNotLastOwner($tenantId);
        }

        $user->syncRoles($role === null ? [] : [$role->value]);

        $this->audit->record(
            AuditAction::UserRoleChanged,
            $actor,
            entityType: 'user',
            entityId: $user->uuid,
            reason: $reason,
            metadata: ['role' => $role?->value],
        );

        return $user;
    }

    /**
     * @throws UserNotFound
     */
    private function findInTenant(string $tenantId, int $userId): User
    {
        $user = User::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->find($userId);

        if ($user === null) {
            throw new UserNotFound(
                "Pengguna #{$userId} tidak ditemukan dalam tenant ini."
            );
        }

        return $user;
    }

    /**
     * @throws LastOwnerProtected
     */
    private function assertNotLastOwner(string $tenantId): void
    {
        $activeOwners = User::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->role(Role::Owner->value)
            ->count();

        if ($activeOwners <= 1) {
            throw new LastOwnerProtected(
                'Owner terakhir tidak dapat dinonaktifkan atau diturunkan perannya; tetapkan owner lain terlebih dahulu.'
            );
        }
    }

    private function resolveTenantId(User $actor): string
    {
        return $this->tenantId ?? $actor->tenant_id;
    }
}
