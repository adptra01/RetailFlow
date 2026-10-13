<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\CreateSale;
use App\Enums\Module;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSaleRequest;
use App\Models\Transaction;
use App\Support\Api\ApiMeta;
use App\Support\Api\SaleCheckoutMapper;
use App\Support\Api\SaleLocator;
use App\Support\Api\SalePayload;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Features\FeatureProfileManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/sales` — checkout dari klien perangkat (§18).
 *
 * Aturan yang ditegakkan di sini:
 *  - **harga dihitung server** (§18): klien hanya mengirim produk + kuantitas;
 *    `SaleCheckoutMapper` mengambil harga sah dari master;
 *  - **idempotensi**: `client_request_id` yang sama mengembalikan penjualan
 *    yang sama (200) tanpa menulis ulang apa pun (§11.1);
 *  - **isolasi tenant** (§12): tenant dari pengguna, pencarian penjualan lama
 *    dibatasi `tenant_id`, perangkat/lokasi/pelanggan wajib milik tenant itu;
 *  - **otorisasi FR-12**: `access.module` selalu, dan `discount.apply` bila
 *    ada diskon (baris atau transaksi);
 *  - **sisi server tidak memantulkan ke outbox** (§11.1): `enqueue_for_sync`
 *    dimatikan karena penjualan ini sudah tercatat di server.
 */
class StoreSaleController extends Controller
{
    public function __invoke(
        StoreSaleRequest $request,
        SaleCheckoutMapper $mapper,
        SaleLocator $locator,
    ): JsonResponse {
        $user = $request->user();

        ActionAuthorizer::assert($user, Permission::AccessModule);

        $tenant = $user->tenant;

        if ($tenant === null) {
            return response()->json(['message' => 'Pengguna belum terhubung ke toko mana pun.'], 403);
        }

        // FR-13: mematikan modul kasir berarti kasir tidak dipakai untuk toko ini.
        if (! FeatureProfileManager::for($tenant)->isEnabled(Module::Cashier)) {
            return response()->json(['message' => 'Modul kasir tidak aktif untuk toko ini.'], 403);
        }

        $clientRequestId = (string) $request->validated('client_request_id');
        $uuid = $request->validated('uuid');

        // Idempotensi (§11.1): balasan ulang tidak boleh menulis penjualan baru.
        if (is_string($uuid) && $uuid !== '') {
            $owner = $locator->findByUuidAnyTenant($uuid);

            if ($owner !== null) {
                // `uuid` unik global. Bila sudah dipakai tenant lain, jangan
                // menulis apa pun dan jangan mengembalikan data tenant lain
                // (§12) — laporkan bentrokan identitas sebagai konflik.
                if ($owner->tenant_id !== $tenant->id) {
                    return response()->json(['message' => 'Identitas penjualan (uuid) sudah dipakai.'], 409);
                }

                return $this->respond($owner, replay: true);
            }
        }

        $existing = $locator->findByIdempotencyKey($tenant->id, $clientRequestId);

        if ($existing !== null) {
            return $this->respond($existing, replay: true);
        }

        if ($this->hasDiscount($request)) {
            ActionAuthorizer::assert($user, Permission::ApplyDiscount);
        }

        // Harga dibaca dari master dan penjualan ditulis dalam SATU transaksi
        // basis data, agar harga yang dihitung server adalah harga yang dipakai.
        $transaction = DB::transaction(function () use ($request, $mapper, $user, $tenant, $clientRequestId, $uuid): Transaction {
            $data = $mapper->map($request->validated(), $user, $tenant);

            $data['client_request_id'] = $clientRequestId;

            if (is_string($uuid) && $uuid !== '') {
                // Identitas sinkronisasi boleh ditentukan perangkat agar sama
                // dengan yang tersimpan di perangkat itu (§11.1).
                $data['uuid'] = $uuid;
            }

            return (new CreateSale)->handle($data);
        });

        return $this->respond($transaction, replay: false);
    }

    private function respond(Transaction $transaction, bool $replay): JsonResponse
    {
        $transaction->load(['items.product', 'payments', 'receivable', 'customer']);

        $payload = ApiMeta::with([
            'sale' => SalePayload::from($transaction),
            'sync' => [
                'status' => $replay ? 'already_applied' : 'applied',
                'idempotent_replay' => $replay,
                'device_id' => $transaction->device_id,
                'client_request_id' => $transaction->client_request_id,
            ],
        ]);

        return response()->json($payload, $replay ? 200 : 201);
    }

    private function hasDiscount(StoreSaleRequest $request): bool
    {
        $discount = $request->validated('discount');

        if (is_array($discount) && (float) ($discount['value'] ?? 0) > 0) {
            return true;
        }

        foreach ($request->validated('items') as $item) {
            if ((int) ($item['discount_minor'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
