<?php

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
*/

Route::prefix('v1')->group(function () {
    Route::middleware('device')->group(function () {
        Route::post('sync/push', SyncPushController::class)->name('api.v1.sync.push');
        Route::get('sync/pull', SyncPullController::class)->name('api.v1.sync.pull');
    });
});
