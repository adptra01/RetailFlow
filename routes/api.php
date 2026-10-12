<?php

use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\SyncPullController;
use App\Http\Controllers\Api\V1\SyncPushController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (§18)
|--------------------------------------------------------------------------
|
| Endpoint sinkronisasi diautentikasi lewat token perangkat (middleware
| `device`), bukan sesi pengguna. Tenant ditentukan dari perangkat, bukan
| dari body request (§12).
|
| Endpoint webhook pembayaran berada DI LUAR grup itu: pemanggilnya adalah
| penyedia pembayaran, yang membuktikan identitasnya dengan tanda tangan
| (plan §14/§15), bukan token perangkat.
|
*/

Route::prefix('v1')->group(function () {
    Route::middleware('device')->group(function () {
        Route::post('sync/push', SyncPushController::class)->name('api.v1.sync.push');
        Route::get('sync/pull', SyncPullController::class)->name('api.v1.sync.pull');
    });

    Route::post('payments/webhooks/{provider}', PaymentWebhookController::class)
        ->name('api.v1.payments.webhook');
});
