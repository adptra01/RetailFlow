<?php

namespace App\Exceptions\Users;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tenant tidak boleh dibiarkan tanpa owner yang aktif: pemilik terakhir
 * tidak dapat dinonaktifkan atau diturunkan perannya (FR-12, keamanan).
 */
class LastOwnerProtected extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
