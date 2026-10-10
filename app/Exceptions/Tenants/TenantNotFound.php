<?php

namespace App\Exceptions\Tenants;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tenant tidak ditemukan saat mengonfigurasi profil fitur.
 */
class TenantNotFound extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 404);
    }
}
