<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengikat tenant dari PENGGUNA terautentikasi (§12, §15).
 *
 * Endpoint API yang bertindak atas nama manusia memakai token pengguna
 * (`auth:sanctum`) — bukan token perangkat — sehingga tenant tidak pernah
 * datang dari perangkat. Middleware ini menutup celah terpenting: tanpa
 * ikatan konteks, scope tenant global tidak aktif dan query apa pun bisa
 * membaca data tenant lain (§12: "Semua query server harus membatasi data
 * berdasarkan tenant yang telah diotorisasi").
 *
 * Tenant diambil dari relasi pengguna, bukan dari body/query permintaan.
 * Pengguna tanpa tenant dibiarkan tanpa konteks; use case yang memerlukan
 * tenant akan menolaknya sendiri.
 */
class BindUserTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->tenant_id !== null) {
            $tenant = Tenant::query()->find($user->tenant_id);

            if ($tenant !== null) {
                app(TenantContext::class)->set($tenant);
            }
        }

        return $next($request);
    }
}
