<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\VoidSale;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VoidSaleRequest;
use App\Support\Api\ApiMeta;
use App\Support\Api\SaleLocator;
use App\Support\Api\SalePayload;
use App\Support\Auth\ActionAuthorizer;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/sales/{id}/void` — pembatalan terkontrol (§17.4, §18).
 *
 * Penjualan tidak pernah dihapus: statusnya menjadi `void`, alasannya wajib,
 * izin `transaction.void` diperiksa (FR-12) atas nama pengguna token — bukan
 * dari body. Pengulangan bersifat idempoten (`already_void`, stok tidak
 * dikembalikan dua kali). Pencarian penjualan dibatasi tenant (§12).
 */
class VoidSaleController extends Controller
{
    public function __invoke(VoidSaleRequest $request, SaleLocator $locator, string $sale): JsonResponse
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

        $result = (new VoidSale)->handle([
            'transaction_id' => $transaction->getKey(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->getKey(),
            'reason' => (string) $request->validated('reason'),
            'device_id' => $request->validated('device_id'),
            'restore_stock' => $request->validated('restore_stock'),
            // §11.1: pembatalan ini lahir di server, bukan di perangkat.
            'enqueue_for_sync' => false,
        ], $user);

        $voided = $result['transaction'];
        $voided->load(['items.product', 'payments', 'receivable', 'customer']);

        return response()->json(ApiMeta::with([
            'sale' => SalePayload::from($voided),
            'void' => [
                'already_void' => $result['already_void'],
                'stock_restored' => $result['stock_restored'],
                'restored_items' => $result['restored_items'],
                'receivable_voided' => $result['receivable_voided'],
            ],
        ]));
    }
}
