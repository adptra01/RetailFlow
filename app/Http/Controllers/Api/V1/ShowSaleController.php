<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiMeta;
use App\Support\Api\SaleLocator;
use App\Support\Api\SalePayload;
use App\Support\Auth\ActionAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/sales/{id}` — rincian penjualan (§18).
 *
 * `{id}` boleh UUID sinkronisasi atau id numerik server. Tenant selalu dari
 * pengguna terautentikasi dan kueri dibatasi `tenant_id` (§12), sehingga
 * penjualan tenant lain TIDAK dapat dilihat — jawabannya 404 yang sama seperti
 * penjualan yang memang tidak ada (tidak membocorkan keberadaan data).
 */
class ShowSaleController extends Controller
{
    public function __invoke(Request $request, SaleLocator $locator, string $sale): JsonResponse
    {
        $user = $request->user();

        ActionAuthorizer::assert($user, Permission::AccessModule);

        $tenant = $user->tenant;

        if ($tenant === null) {
            return response()->json(['message' => 'Pengguna belum terhubung ke toko mana pun.'], 403);
        }

        $transaction = $locator->find($tenant->id, $sale);

        if ($transaction === null) {
            return response()->json(['message' => 'Penjualan tidak ditemukan.'], 404);
        }

        $transaction->load(['items.product', 'payments', 'receivable', 'customer']);

        return response()->json(ApiMeta::with([
            'sale' => SalePayload::from($transaction),
        ]));
    }
}
