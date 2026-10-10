<?php

namespace App\Exceptions\Sales;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientStock extends \RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Stok tidak mencukupi untuk produk #{$this->productId}: tersedia {$this->available}, diminta {$this->requested}.",
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
