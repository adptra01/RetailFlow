<?php

namespace App\Exceptions\Sales;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvalidPaymentAmount extends \RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
