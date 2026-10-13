<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Module;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Support\Api\ApiMeta;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Features\FeatureProfileManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/bootstrap` (§18, §19).
 *
 * Satu panggilan yang menyiapkan klien setelah login: identitas toko &
 * pengguna, modul efektif (FR-13), kebijakan domain yang memengaruhi
 * perilaku kasir (piutang §FR-07, stok negatif §11.4, sinkronisasi §11),
 * metode pembayaran yang boleh dipilih (FR-05), dan daftar lokasi aktif
 * (§12 — dipakai saat mendaftarkan perangkat).
 *
 * Otorisasi: token pengguna + izin FR-12 `access.module`. Modul yang tidak
 * aktif TIDAK disembunyikan dari sini tetapi dilaporkan apa adanya lewat
 * `modules`, sehingga klien dapat menyembunyikan menunya sendiri (FR-13:
 * mematikan modul hanya soal visibilitas, bukan data).
 */
class BootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        ActionAuthorizer::assert($user, Permission::AccessModule);

        $tenant = $user->tenant;

        if ($tenant === null) {
            return response()->json(['message' => 'Pengguna belum terhubung ke toko mana pun.'], 403);
        }

        $modules = FeatureProfileManager::for($tenant);

        return response()->json(ApiMeta::with([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'business_type' => $tenant->business_type,
                'feature_profile' => $tenant->feature_profile,
                'ui_complexity' => $tenant->ui_complexity,
                'onboarding_state' => $tenant->onboarding_state,
                'currency_code' => $tenant->currency_code,
                'timezone' => $tenant->timezone,
            ],
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->unique()->values()->all(),
            ],
            'modules' => $modules->moduleNames(),
            'payment_methods' => collect(PaymentMethod::selectable())
                ->map(fn (PaymentMethod $method) => [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'allows_change' => $method->allowsChange(),
                ])
                ->values()
                ->all(),
            'locations' => Location::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'address'])
                ->map(fn (Location $location) => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'address' => $location->address,
                ])
                ->all(),
            'policies' => [
                // FR-07: jatuh tempo & batas kredit pelanggan.
                'receivables' => [
                    'default_due_days' => (int) config('pos.receivables.default_due_days'),
                    'enforce_credit_limit' => (bool) config('pos.receivables.enforce_credit_limit'),
                ],
                // §11.4: bolehkah penjualan membuat stok negatif di perangkat.
                'allow_negative_stock' => (bool) config('pos.inventory.allow_negative_stock'),
                // §17.4: kebijakan stok saat void/retur.
                'restore_stock_on_void' => (bool) config('pos.sales.restore_stock_on_void'),
                'restore_stock_on_return' => (bool) config('pos.sales.restore_stock_on_return'),
                // §11: batas batch sinkronisasi yang diterima server.
                'sync' => [
                    'max_batch' => (int) config('pos.sync.max_batch'),
                    'batch_size' => (int) config('pos.offline.batch_size'),
                ],
            ],
        ]));
    }
}
