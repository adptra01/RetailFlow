<?php

namespace App\Support\Auth;

use App\Enums\Permission;
use App\Exceptions\Auth\ActionForbidden;
use App\Models\User;

/**
 * Gerbang otorisasi operasi sensitif (FR-12).
 *
 * - Aktor nonaktif ditolak untuk semua operasi sensitif.
 * - Izin diperiksa lewat Gate (`$user->can(...)`), yang sudah dihubungkan
 *   ke katalog spatie permission di `AppServiceProvider`.
 * - `assertIf()` membolehkan use case yang DAPAT berjalan tanpa aktor
 *   (CLI/otorisasi di lapisan lain) tetap memeriksa izin bila aktor
 *   diberikan — tanpa memaksa parameter wajib di seluruh pemanggil.
 */
final class ActionAuthorizer
{
    public static function assert(User $actor, Permission|string $permission): void
    {
        $permission = $permission instanceof Permission
            ? $permission
            : Permission::tryFrom($permission);

        if (! $actor->is_active) {
            throw new ActionForbidden('Akun pengguna nonaktif — login ulang untuk melanjutkan.');
        }

        if ($permission === null) {
            return;
        }

        if (! $actor->can($permission->value)) {
            $label = $permission->label();

            throw new ActionForbidden(
                "Aksi memerlukan izin «{$label}» ({$permission->value})."
            );
        }
    }

    public static function assertIf(?User $actor, Permission|string $permission): void
    {
        if ($actor !== null) {
            self::assert($actor, $permission);
        }
    }
}