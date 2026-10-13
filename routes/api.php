<?php

use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\DeviceRegisterController;
use App\Http\Controllers\Api\V1\LoginController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProductIndexController;
use App\Http\Controllers\Api\V1\ShowSaleController;
use App\Http\Controllers\Api\V1\StoreSaleController;
use App\Http\Controllers\Api\V1\SyncPullController;
use App\Http\Controllers\Api\V1\SyncPushController;
use App\Http\Controllers\Api\V1\VoidSaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (§18)
|--------------------------------------------------------------------------
|
| Dua jenis identitas dipakai (§15):
|
|  - Token PERANGKAT (middleware `device`) untuk endpoint sinkronisasi;
|    tenant ditentukan dari perangkat, bukan dari body request (§12).
|  - Token PENGGUNA (Sanctum, middleware `auth:sanctum`) untuk endpoint yang
|    bertindak atas nama manusia, mis. mendaftarkan perangkat atau membuat
|    penjualan. Izin FR-12 tetap diperiksa di use case.
|
| `auth/login` sendiri terbuka (kredensial = autentikasinya) tetapi dibatasi
| lajunya untuk mencegah penebakan kata sandi (NFR-03).
|
| Endpoint webhook pembayaran berada DI LUAR kedua grup itu: pemanggilnya
| adalah penyedia pembayaran, yang membuktikan identitasnya dengan tanda
| tangan (plan §14/§15), bukan token.
|
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/login', LoginController::class)
        ->middleware('throttle:'.max(1, (int) config('pos.api.login_attempts_per_minute', 10)).',1')
        ->name('api.v1.auth.login');

    Route::middleware(['auth:sanctum', 'user.tenant'])->group(function () {
        Route::post('devices/register', DeviceRegisterController::class)
            ->name('api.v1.devices.register');

        Route::get('bootstrap', BootstrapController::class)
            ->name('api.v1.bootstrap');

        Route::get('products', ProductIndexController::class)
            ->name('api.v1.products.index');

        Route::post('sales', StoreSaleController::class)
            ->name('api.v1.sales.store');

        // `{sale}` menerima UUID sinkronisasi atau id numerik server.
        Route::get('sales/{sale}', ShowSaleController::class)
            ->name('api.v1.sales.show');

        Route::post('sales/{sale}/void', VoidSaleController::class)
            ->name('api.v1.sales.void');
    });

    Route::middleware('device')->group(function () {
        Route::post('sync/push', SyncPushController::class)->name('api.v1.sync.push');
        Route::get('sync/pull', SyncPullController::class)->name('api.v1.sync.pull');
    });

    Route::post('payments/webhooks/{provider}', PaymentWebhookController::class)
        ->name('api.v1.payments.webhook');
});
