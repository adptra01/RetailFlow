<?php

namespace App\Exceptions\Backup;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Basis untuk seluruh kondisi domain backup (FR-11).
 */
abstract class BackupException extends RuntimeException
{
    protected int $status = 422;

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
