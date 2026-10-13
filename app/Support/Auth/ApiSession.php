<?php

namespace App\Support\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Hasil login klien perangkat: token API plaintext (dikembalikan SEKALI),
 * identitas pengguna, tenant, dan kemampuan yang melekat pada token.
 *
 * Token mewakili MANUSIA (§15), bukan perangkat: identitas perangkat tetap
 * token di tabel `devices` (§12) yang dipakai endpoint sinkronisasi.
 */
final readonly class ApiSession
{
    /**
     * @param  list<string>  $abilities
     * @param  list<string>  $roles
     */
    public function __construct(
        public User $user,
        public Tenant $tenant,
        public string $token,
        public ?Carbon $expiresAt,
        public array $abilities,
        public array $roles,
    ) {}
}
