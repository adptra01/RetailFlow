<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sync\ReceiveSyncPush;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncPushRequest;
use App\Support\Devices\DeviceContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/sync/push` (§18).
 *
 * Menerima batch operasi dari perangkat terautentikasi, menyimpannya dalam
 * satu transaksi, dan mengembalikan hasil per operasi (§11.3 langkah 6–7).
 */
class SyncPushController extends Controller
{
    public function __invoke(SyncPushRequest $request, DeviceContext $devices, TenantContext $tenants): JsonResponse
    {
        $deviceId = $devices->id();
        $tenantId = $tenants->id();

        $result = (new ReceiveSyncPush($tenantId, $deviceId))
            ->handle($request->validated('operations'));

        return response()->json([
            'device_id' => $deviceId,
            'tenant_id' => $tenantId,
            'server_time' => now()->toIso8601String(),
            'accepted' => $result['accepted'],
            'duplicates' => $result['duplicates'],
            'results' => $result['results'],
        ]);
    }
}
