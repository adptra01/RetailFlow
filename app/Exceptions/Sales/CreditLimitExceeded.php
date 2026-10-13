<?php

namespace App\Exceptions\Sales;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batas kredit pelanggan terlampaui (FR-07).
 *
 * Hanya dilempar ketika toko mengaktifkan kebijakan pembatasan
 * (`pos.receivables.enforce_credit_limit`). Saat kebijakan hanya berupa
 * peringatan, penjualan tetap dicatat dan UI yang menampilkan warnanya.
 */
class CreditLimitExceeded extends \RuntimeException
{
    public static function forCustomer(Customer $customer, int $limitMinor, int $projectedMinor): self
    {
        return new self(sprintf(
            'Batas kredit pelanggan %s terlampaui: limit Rp%s, piutang menjadi Rp%s.',
            $customer->name,
            number_format($limitMinor, 0, ',', '.'),
            number_format($projectedMinor, 0, ',', '.'),
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
