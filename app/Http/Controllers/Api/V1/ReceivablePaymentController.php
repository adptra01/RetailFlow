<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Enums\Module;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReceivablePaymentRequest;
use App\Models\ReceivablePayment;
use App\Support\Api\ApiMeta;
use App\Support\Api\ReceivableLocator;
use App\Support\Api\ReceivablePaymentPayload;
use App\Support\Api\SalePayload;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Features\FeatureProfileManager;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/receivables/{id}/payments` — penagihan piutang (§18, FR-07).
 *
 * Aturan yang ditegakkan:
 *  - **isolasi tenant** (§12): piutang dicari dengan batas `tenant_id`; piutang
 *    tenant lain dijawab 404 yang sama dengan piutang yang tidak ada;
 *  - **idempotensi** (FR-07/§18): `client_request_id` yang sama mengembalikan
 *    pembayaran yang sama (200) tanpa menagih dua kali;
 *  - **otorisasi FR-12**: `access.module`; modul `receivables` yang dimatikan
 *    untuk toko itu menolak dengan 403 (FR-13);
 *  - **kejujuran saldo**: nominal positif, tidak melebihi sisa, piutang lunas
 *    tidak dapat dibayar lagi — ditegakkan `RecordReceivablePayment` (422).
 */
class ReceivablePaymentController extends Controller
{
    public function __invoke(
        StoreReceivablePaymentRequest $request,
        ReceivableLocator $locator,
        string $receivable,
    ): JsonResponse {
        $user = $request->user();

        ActionAuthorizer::assert($user, Permission::AccessModule);

        $tenant = $user->tenant;

        if ($tenant === null) {
            return response()->json(['message' => 'Pengguna belum terhubung ke toko mana pun.'], 403);
        }

        if (! FeatureProfileManager::for($tenant)->isEnabled(Module::Receivables)) {
            return response()->json(['message' => 'Modul piutang tidak aktif untuk toko ini.'], 403);
        }

        $model = $locator->find($tenant->id, $receivable);

        if ($model === null) {
            return response()->json(['message' => 'Piutang tidak ditemukan.'], 404);
        }

        $clientRequestId = (string) $request->validated('client_request_id');

        $existing = $locator->findByIdempotencyKey($tenant->id, $clientRequestId);

        if ($existing !== null) {
            return $this->respond($existing, replay: true);
        }

        $payment = (new RecordReceivablePayment)->handle([
            'receivable_id' => $model->getKey(),
            'amount_minor' => (int) $request->validated('amount_minor'),
            'payment_method' => (string) $request->validated('payment_method'),
            'user_id' => $user->getKey(),
            'device_id' => $request->validated('device_id'),
            'notes' => $request->validated('notes'),
            'client_request_id' => $clientRequestId,
            'tenant_id' => $tenant->id,
        ]);

        return $this->respond($payment, replay: false);
    }

    private function respond(ReceivablePayment $payment, bool $replay): JsonResponse
    {
        return response()->json(ApiMeta::with([
            'payment' => ReceivablePaymentPayload::from($payment),
            // Saldo piutang sesudah pembayaran ini dicatat — sumber kebenaran
            // adalah catatan pembayaran, bukan angka yang dihitung klien.
            'receivable' => SalePayload::receivable($payment->receivable()->first()),
            'sync' => [
                'status' => $replay ? 'already_applied' : 'applied',
                'idempotent_replay' => $replay,
                'client_request_id' => $payment->client_request_id,
            ],
        ]), $replay ? 200 : 201);
    }
}
