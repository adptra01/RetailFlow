<?php

namespace App\Exceptions\Sales;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Piutang tidak ditemukan atau sudah lunas (FR-07).
 */
class ReceivableClosed extends \RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
