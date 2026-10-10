<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\Tenant;
use App\Support\Devices\DeviceContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi perangkat untuk API sinkronisasi (§15, §11.3 langkah 4–5).
 *
 * Token perangkat (item Sprint 4.1) mengidentifikasi tenant secara sah:
 * tenant TIDAK PERNAH diambil dari body request — selalu dari perangkat yang
 * terautentikasi (§12: "Jangan mengandalkan `tenant_id` dari request
 * pengguna tanpa memverifikasinya"). Perangkat yang dicabut ditolak karena
 * `Device::findByToken` hanya mengembalikan perangkat aktif.
 *
 * Middleware ini juga "menyentuh" perangkat (`last_seen_at`) sebagai
 * heartbeat sinkronisasi.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokenFrom($request);

        if ($token === null) {
            return response()->json(['message' => 'Token perangkat tidak diberikan.'], 401);
        }

        $device = Device::findByToken($token);

        if ($device === null) {
            return response()->json(['message' => 'Perangkat tidak dikenal atau telah dicabut.'], 401);
        }

        $tenant = Tenant::query()->find($device->tenant_id);

        if ($tenant === null) {
            return response()->json(['message' => 'Tenant perangkat tidak ditemukan.'], 401);
        }

        app(TenantContext::class)->set($tenant);
        app(DeviceContext::class)->set($device);

        $device->markSeen($this->appVersion($request));

        $request->attributes->set('device', $device);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    /**
     * Token dari `Authorization: Bearer ...` (utama) atau `X-Device-Token`.
     */
    private function tokenFrom(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = $request->header('X-Device-Token');

        return is_string($header) && $header !== '' ? $header : null;
    }

    private function appVersion(Request $request): ?string
    {
        $version = $request->header('X-App-Version');

        return is_string($version) && $version !== '' ? $version : null;
    }
}
