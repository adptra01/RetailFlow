<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sync\ApplySyncOperations;
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
 * satu transaksi, lalu menerapkannya ke domain secara idempoten (§11.3
 * langkah 6–7). Mengembalikan hasil per operasi.
 */
class SyncPushController extends Controller
{
    public function __invoke(SyncPushRequest $request, DeviceContext $devices, TenantContext $tenants): JsonResponse
    {
        $deviceId = $devices->id();
        $tenantId = $tenants->id();

        $operations = $request->validated('operations');

        $receipt = (new ReceiveSyncPush($tenantId, $deviceId))->handle($operations);

        $operationIds = array_map(
            static fn (array $operation): string => (string) $operation['operation_id'],
            $operations,
        );

        $statuses = (new ApplySyncOperations($tenantId, $deviceId))->handle($operationIds);

        $results = array_map(static function (array $result) use ($statuses): array {
            $isDuplicate = ($result['status'] ?? null) === 'duplicate';
            $final = $statuses[$result['operation_id']] ?? null;

            return [
                'operation_id' => $result['operation_id'],
                // Status akhir penerapan: applied|rejected|conflict|received.
                'status' => $final['status'] ?? $result['status'],
                'duplicate' => $isDuplicate,
                'server_sequence' => $result['server_sequence'] ?? null,
                'entity_id' => $final['entity_id'] ?? null,
                'error' => $final['error'] ?? null,
            ];
        }, $receipt['results']);

        return response()->json([
            'device_id' => $deviceId,
            'tenant_id' => $tenantId,
            'server_time' => now()->toIso8601String(),
            'accepted' => $receipt['accepted'],
            'duplicates' => $receipt['duplicates'],
            'results' => $results,
        ]);
    }
}
