<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Devices\RegisterDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterDeviceRequest;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/devices/register` (§18, §12, §15).
 *
 * Pengguna terautentikasi (token Sanctum) mendaftarkan sebuah perangkat dan
 * menerima token perangkat plaintext SEKALI. Tenant diambil dari pengguna,
 * BUKAN dari body permintaan (§12), dan izin FR-12 diperiksa di use case
 * (`RegisterDevice` → `user.manage`).
 *
 * Token perangkat inilah yang dipakai endpoint sinkronisasi
 * (`/api/v1/sync/push`, `/api/v1/sync/pull`) dan dicabut lewat
 * `revoked_at` sehingga perangkat lama ditolak (§15).
 */
class DeviceRegisterController extends Controller
{
    public function __invoke(RegisterDeviceRequest $request): JsonResponse
    {
        $user = $request->user();

        $registration = (new RegisterDevice((string) $user->tenant_id))->handle(
            actor: $user,
            name: (string) $request->validated('name'),
            platform: (string) $request->validated('platform'),
            locationId: $request->validated('location_id'),
            appVersion: $request->validated('app_version'),
            reason: 'Pendaftaran perangkat lewat API klien.',
        );

        $device = $registration->device;

        return response()->json([
            'device' => [
                'id' => $device->id,
                'name' => $device->device_name,
                'platform' => $device->platform,
                'location_id' => $device->location_id,
                'app_version' => $device->app_version,
                'token_prefix' => $device->token_prefix,
                'registered_at' => $device->created_at?->toIso8601String(),
            ],
            'token' => $registration->token,
            'token_type' => 'Device',
            'server_time' => now()->toIso8601String(),
        ], 201);
    }
}
