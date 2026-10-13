<?php

namespace App\Exceptions\Receivables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catatan penagihan tidak dapat dicatat (FR-07).
 */
class CollectionNoteNotAllowed extends \RuntimeException
{
    public static function noteRequired(): self
    {
        return new self('Catatan penagihan tidak boleh kosong.');
    }

    public static function receivableNotFound(): self
    {
        return new self('Piutang yang akan dicatat penagihannya tidak ditemukan.');
    }

    public static function invalidChannel(string $channel): self
    {
        return new self("Saluran penagihan '{$channel}' tidak dikenal.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
