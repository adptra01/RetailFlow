<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncPullRequest;
use App\Support\Devices\DeviceContext;
use App\Support\Sync\SyncChangeFeed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/sync/pull?cursor=...` (§18).
 *
 * Mengembalikan perubahan server sejak kursor terakhir (§11.3 langkah 9).
 * Sumber perubahan disalurkan lewat seam `SyncChangeFeed`; item Sprint 4.4
 * menyediakan implementasi kursor sebenarnya.
 */
class SyncPullController extends Controller
{
    public function __invoke(
        SyncPullRequest $request,
        SyncChangeFeed $feed,
        DeviceContext $devices,
        TenantContext $tenants,
    ): JsonResponse {
        $limit = (int) ($request->validated('limit') ?? config('pos.offline.batch_size', 100));
        $cursor = $request->validated('cursor');

        $batch = $feed->since($cursor, $limit);

        $device = $devices->get();

        if ($device !== null && $batch->cursor !== null) {
            $device->update(['last_sync_cursor' => $batch->cursor]);
        }

        return response()->json([
            'device_id' => $device?->id,
            'tenant_id' => $tenants->id(),
            'server_time' => now()->toIso8601String(),
            'cursor' => $batch->cursor,
            'has_more' => $batch->hasMore,
            'changes' => $batch->changes,
        ]);
    }
}
