<?php

use App\Payments\NullPaymentGateway;

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

    'sales' => [
        // §17.4 "Retur dan void": "Pengembalian stok hanya dilakukan sesuai
        // kebijakan retur."
        //
        // true (default) = pembatalan (void) mengembalikan stok lewat mutasi
        //   balik (`sale_void`) sebesar yang pernah dikurangi saat penjualan;
        // false = pembatalan hanya menandai transaksi batal, stok dibiarkan
        //   (mis. barang sudah diserahkan/rusak dan tidak kembali ke rak).
        'restore_stock_on_void' => (bool) env('POS_RESTORE_STOCK_ON_VOID', true),
    ],

    'inventory' => [
        // §11.4 "Konflik stok": "Owner dapat mengaktifkan kebijakan stok
        // negatif atau membatasi penjualan berdasarkan kebutuhan bisnis."
        //
        // false (default) = batasi penjualan: operasi perangkat yang membuat
        //   stok negatif tidak diterapkan dan menjadi kasus konflik terbuka
        //   yang menunggu penyelesaian.
        // true = izinkan stok negatif: penjualan diterapkan apa adanya, dan
        //   konflik tetap dicatat agar tidak disembunyikan.
        'allow_negative_stock' => (bool) env('POS_ALLOW_NEGATIVE_STOCK', false),
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
        // Batas jumlah operasi dalam satu batch push (§11.5 "batch dengan
        // ukuran terbatas").
        'max_batch' => (int) env('POS_SYNC_MAX_BATCH', 500),
        // URL server tujuan push. Default: instalasi ini sendiri (mode
        // web/PWA on-prem) atau dikonfigurasi ke server cloud (mode native).
        'server_url' => env('POS_SYNC_SERVER_URL', env('APP_URL', 'http://localhost')),
        // Timeout request push (detik) sebelum hasil dianggap tidak diketahui
        // dan operasi diulang (§11.3).
        'request_timeout' => (int) env('POS_SYNC_REQUEST_TIMEOUT', 10),
        // Entri `in_flight` yang lebih tua dari ini dianggap terbengkalai
        // (crash saat mengirim) dan dikembalikan ke `pending` (§13).
        'in_flight_timeout' => (int) env('POS_SYNC_IN_FLIGHT_TIMEOUT', 300),
        // Token perangkat milik instalasi ini sendiri (dipakai tombol
        // "Sinkronkan sekarang" pada UI lokal, §11.5). Pada build native,
        // token ini berasal dari penyimpanan aman perangkat; pada mode
        // web/on-prem dapat diisi lewat env untuk sinkronisasi ke server pusat.
        'device_token' => env('POS_SYNC_DEVICE_TOKEN'),
    ],

    // Versi rilis & skema database (plan §21: "Setiap rilis memiliki versi
    // skema database"). Bukan nomor migration; penanda skema yang divalidasi
    // saat restore backup (FR-11).
    'version' => env('POS_APP_VERSION', '0.4.0'),
    'schema_version' => env('POS_SCHEMA_VERSION', '0.4.0'),

    /*
    |--------------------------------------------------------------------------
    | Payment gateway (Mode B, plan §14, FR-05)
    |--------------------------------------------------------------------------
    |
    | Aplikasi wajib tetap berjalan tanpa payment gateway (FR-01); karenanya
    | default-nya adalah adaptor `none` (no-op yang menolak dengan jelas).
    |
    | KREDENSIAL TIDAK BOLEH DISIMPAN DI DATABASE LOKAL (FR-05), jadi hanya
    | dibaca dari environment (atau secret store platform) di sini. Adaptor
    | konkretnya belum ada sampai penyedia resmi dipilih dan disepakati
    | (plan §14 "E-wallet": jangan menjanjikan integrasi sebelum dokumentasi,
    | kontrak, biaya, dan proses sertifikasi diperiksa).
    |
    */

    'payments' => [
        // Penyedia yang dipakai instalasi ini.
        'default_gateway' => env('POS_PAYMENT_GATEWAY', NullPaymentGateway::KEY),

        // Peta kunci penyedia → kelas adaptor. Tambahkan penyedia baru di sini
        // (atau lewat konfigurasi penerapan) tanpa mengubah lapisan domain.
        'gateways' => [
            NullPaymentGateway::KEY => NullPaymentGateway::class,
        ],

        // Kredensial adaptor (dibaca adaptor dari sini, bukan dari DB).
        'credentials' => [
            'key' => env('POS_PAYMENT_GATEWAY_KEY'),
            'secret' => env('POS_PAYMENT_GATEWAY_SECRET'),
            'webhook_secret' => env('POS_PAYMENT_GATEWAY_WEBHOOK_SECRET'),
        ],

        // Timeout panggilan HTTP ke penyedia (detik). Timeout BUKAN berarti
        // gagal: hasilnya tidak diketahui dan harus direkonsiliasi (§14
        // "Penanganan timeout").
        'timeout' => (int) env('POS_PAYMENT_GATEWAY_TIMEOUT', 15),

        // Toleransi stempel waktu webhook (detik) untuk mencegah replay (§15).
        'webhook_tolerance' => (int) env('POS_PAYMENT_GATEWAY_WEBHOOK_TOLERANCE', 300),
    ],

    'backup' => [
        // Direktori tempat manifest + snapshot default disimpan. Bisa diarahkan
        // ke media eksternal (FR-11: "Backup dapat disimpan ke media eksternal").
        'path' => env('POS_BACKUP_PATH', storage_path('app/backups')),
        // Enkripsi backup (AES-256-CBC via APP_KEY) — FR-11: opsi konfigurasi
        // untuk data sensitif.
        'encrypt' => (bool) env('POS_BACKUP_ENCRYPT', false),
        // Ambang alert "backup terlalu lama" (FR-10: BackupOverdue).
        'max_age_days' => (int) env('POS_BACKUP_MAX_AGE_DAYS', 7),
    ],

];
