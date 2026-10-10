<?php

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tindakan ditolak karena aktor tidak aktif atau tidak memiliki izin
 * yang diperlukan (FR-12: "Izin untuk operasi sensitif harus diperiksa
 * di server/domain").
 */
class ActionForbidden extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 403);
    }
}