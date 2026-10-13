<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-10: alert lokal (stok menipis, data produk tidak lengkap, piutang jatuh tempo, backup terlalu lama).
Schedule::command('pos:alerts:generate')->hourly()->withoutOverlapping();

// Plan §14 "Rekonsiliasi": selaraskan status charge yang belum final dengan
// penyedia. Tanpa gateway/charge, perintah ini no-op dan murah.
Schedule::command('pos:reconcile')->hourly()->withoutOverlapping();

// §15/NFR-03: buang token API (Sanctum) yang sudah kedaluwarsa agar tabel
// `personal_access_tokens` tidak tumbuh tanpa batas. Token perangkat tidak
// terpengaruh (masa berlakunya dikendalikan `devices.revoked_at`).
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
