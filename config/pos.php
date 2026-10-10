<?php

return [

    /*
    |--------------------------------------------------------------------------
    | POS Universal — Domain Configuration
    |--------------------------------------------------------------------------
    |
    | Central configuration for the POS domain. Values that must never be
    | hardcoded (currency, tenancy mode, sync limits) live here and are
    | exposed through config() so that env() stays out of application code.
    |
    */

    'currency' => [
        'code' => env('POS_CURRENCY_CODE', 'IDR'),
        // Number of decimal digits in the currency's minor unit (IDR = 0).
        'minor_exponent' => (int) env('POS_CURRENCY_MINOR_EXPONENT', 0),
    ],

    'money' => [
        // All monetary values are persisted as integer minor units and never
        // as floating point. See plan §17.1.
        'storage' => 'minor_units',
    ],

    'tenancy' => [
        // Plan §12: begin with a single business per installation while the
        // schema remains multi-tenant ready (tenant_id on every record).
        'mode' => env('POS_TENANCY_MODE', 'single'),
    ],

    'offline' => [
        // Plan decision: offline-first applies to native builds only
        // (NativePHP + SQLite). Web/PWA stays online-first for the MVP.
        'platforms' => ['native'],
        'batch_size' => (int) env('POS_SYNC_BATCH_SIZE', 100),
    ],

    'sync' => [
        'retry_backoff' => [1, 5, 10],
        'max_retries' => (int) env('POS_SYNC_MAX_RETRIES', 5),
    ],

];
