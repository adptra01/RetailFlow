<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-10: alert lokal (stok menipis, data produk tidak lengkap, piutang jatuh tempo, backup terlalu lama).
Schedule::command('pos:alerts:generate')->hourly()->withoutOverlapping();
