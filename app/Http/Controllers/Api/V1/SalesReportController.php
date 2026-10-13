<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Module;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SalesReportRequest;
use App\Reports\SalesReport;
use App\Support\Api\ApiMeta;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Features\FeatureProfileManager;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/reports/sales` — laporan penjualan periode (§18, FR-09).
 *
 * Endpoint ini TIDAK menghitung ulang metriknya: ia memanggil use case laporan
 * yang sama dengan UI (`App\Reports\SalesReport`), sehingga definisi FR-09
 * ("omzet, penerimaan kas, piutang, dan laba tidak boleh diperlakukan sebagai
 * angka yang sama") hanya hidup di satu tempat. Kunci-kunci di dalam `report`
 * mengikuti kontrak use case itu (istilah domain berbahasa Indonesia) —
 * menerjemahkannya kembali di lapisan API akan menciptakan sumber kebenaran
 * kedua yang bisa menyimpang.
 *
 * Otorisasi: izin FR-12 `report.financial.view` diperiksa use case bersama
 * aktornya, dan akses laporan dicatat ke audit (`report.financial.viewed`).
 */
class SalesReportController extends Controller
{
    public function __invoke(SalesReportRequest $request): JsonResponse
    {
        $user = $request->user();

        ActionAuthorizer::assert($user, Permission::AccessModule);

        $tenant = $user->tenant;

        if ($tenant === null) {
            return response()->json(['message' => 'Pengguna belum terhubung ke toko mana pun.'], 403);
        }

        // FR-13: laporan adalah modul tersendiri; mematikannya berarti
        // endpoint ini tidak dipakai untuk toko itu.
        if (! FeatureProfileManager::for($tenant)->isEnabled(Module::Reports)) {
            return response()->json(['message' => 'Modul laporan tidak aktif untuk toko ini.'], 403);
        }

        $report = new SalesReport($tenant->id, $user);
        $date = $request->validated('date');

        $payload = is_string($date) && $date !== ''
            ? $report->daily($date)
            : $report->range($request->validated('from'), $request->validated('to'));

        return response()->json(ApiMeta::with([
            'report' => $payload,
        ]));
    }
}
