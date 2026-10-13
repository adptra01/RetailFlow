<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\IssueApiToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/auth/login` (§18).
 *
 * Menukar kredensial dengan token API per pengguna (Sanctum, §15). Token
 * membawa izin FR-12 sebagai ability sehingga otorisasi dapat diperiksa di
 * setiap use case tanpa membaca ulang peran.
 *
 * Token perangkat (§12) TIDAK dibuat di sini: klien memanggil
 * `POST /api/v1/devices/register` dengan token ini agar perangkat memperoleh
 * identitas sendiri untuk sinkronisasi.
 */
class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $session = (new IssueApiToken)->handle(
            email: (string) $request->validated('email'),
            password: (string) $request->validated('password'),
            deviceName: (string) $request->validated('device_name'),
            platform: $request->validated('platform'),
        );

        return response()->json([
            'token' => $session->token,
            'token_type' => 'Bearer',
            'expires_at' => $session->expiresAt?->toIso8601String(),
            'user' => [
                'id' => $session->user->getKey(),
                'name' => $session->user->name,
                'email' => $session->user->email,
            ],
            'tenant' => [
                'id' => $session->tenant->id,
                'name' => $session->tenant->name,
                'feature_profile' => $session->tenant->feature_profile,
                'currency_code' => $session->tenant->currency_code,
                'timezone' => $session->tenant->timezone,
            ],
            'roles' => $session->roles,
            'permissions' => $session->abilities,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
