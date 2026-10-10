<?php

namespace App\Exceptions\Devices;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Perangkat tidak ditemukan dalam tenant saat mencabut/mengelola.
 */
class DeviceNotFound extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 404);
    }
}
