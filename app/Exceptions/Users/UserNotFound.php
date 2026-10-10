<?php

namespace App\Exceptions\Users;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Pengguna target tidak ditemukan dalam tenant yang sedang dikelola.
 */
class UserNotFound extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 404);
    }
}
