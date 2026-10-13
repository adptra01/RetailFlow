<?php

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Login klien perangkat ditolak (§18 `POST /api/v1/auth/login`, NFR-03).
 *
 * Urutan pemeriksaan disengaja: kredensial diperiksa LEBIH DULU, sehingga
 * status akun (nonaktif / tanpa toko) hanya terungkap kepada pemegang
 * kredensial yang sah — bukan kepada penebak kata sandi.
 *
 * Kredensial salah dijawab 422 (bentuknya kesalahan validasi masukan),
 * sedangkan akun yang sah tetapi tidak boleh masuk dijawab 403.
 */
class LoginRejected extends RuntimeException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('Email atau kata sandi salah.', 422);
    }

    public static function inactive(): self
    {
        return new self('Akun pengguna nonaktif — hubungi pemilik toko.', 403);
    }

    public static function withoutTenant(): self
    {
        return new self('Pengguna belum terhubung ke toko mana pun.', 403);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
