<?php

namespace App\Actions\Auth;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Exceptions\Auth\LoginRejected;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\ApiSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Login klien perangkat (§18 `POST /api/v1/auth/login`, §15).
 *
 * Menukar kredensial pengguna dengan token API (Sanctum) yang mewakili
 * MANUSIA, lengkap dengan daftar izin FR-12 sebagai ability token. Identitas
 * perangkat tetap terpisah: token perangkat di tabel `devices` dibuat lewat
 * `POST /api/v1/devices/register` dan dipakai endpoint sinkronisasi (§12).
 *
 * Jaminan:
 *  - kredensial diperiksa lebih dulu (Hash::check) sehingga status akun tak
 *    bocor kepada penebak kata sandi;
 *  - akun nonaktif dan akun tanpa toko ditolak (NFR-03);
 *  - pencarian pengguna TIDAK bergantung konteks tenant permintaan (email
 *    unik global) sehingga login tidak bisa "tersesat" karena konteks;
 *  - satu perangkat hanya menyisakan satu token aktif: login ulang dengan
 *    nama perangkat sama mencabut token lama perangkat itu, sehingga token
 *    tidak menumpuk tanpa batas;
 *  - setiap login sukses meninggalkan satu baris audit `api.login` (NFR-03).
 */
class IssueApiToken
{
    public function handle(
        string $email,
        string $password,
        string $deviceName,
        ?string $platform = null,
    ): ApiSession {
        $user = $this->findUser($email);

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw LoginRejected::invalidCredentials();
        }

        if (! $user->is_active) {
            throw LoginRejected::inactive();
        }

        $tenant = $user->tenant_id !== null
            ? Tenant::query()->find($user->tenant_id)
            : null;

        if ($tenant === null) {
            throw LoginRejected::withoutTenant();
        }

        $abilities = $user->getAllPermissions()->pluck('name')->unique()->values()->all();
        $roles = $user->getRoleNames()->all();
        $expiresAt = $this->expiresAt();

        // Satu perangkat = satu sesi API aktif.
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, $abilities, $expiresAt);

        (new AuditRecorder($tenant->id))->record(
            AuditAction::ApiLoggedIn,
            $user,
            entityType: 'user',
            entityId: (string) $user->id,
            metadata: [
                'device_name' => $deviceName,
                'platform' => $platform,
                'token_id' => $token->accessToken->getKey(),
                'abilities' => count($abilities),
                'roles' => $roles,
                'expires_at' => $expiresAt?->toIso8601String(),
            ],
        );

        return new ApiSession(
            user: $user,
            tenant: $tenant,
            token: $token->plainTextToken,
            expiresAt: $expiresAt,
            abilities: $abilities,
            roles: $roles,
        );
    }

    /**
     * Pengguna dicari lintas tenant (email unik global): scope tenant global
     * dilepas agar hasil login tidak bergantung konteks permintaan.
     */
    private function findUser(string $email): ?User
    {
        /** @var User|null $user */
        $user = User::query()
            ->withoutGlobalScope('tenant')
            ->where('email', $email)
            ->first();

        return $user;
    }

    /**
     * Token tanpa masa berlaku hanya bila dikonfigurasi 0 (tidak disarankan).
     */
    private function expiresAt(): ?Carbon
    {
        $days = (int) config('pos.api.token_days', 30);

        return $days > 0 ? Carbon::now()->addDays($days) : null;
    }
}
