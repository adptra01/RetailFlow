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

        // FR-08 "Retur penjualan dengan alasan" + §17.4 "Pengembalian stok
        // hanya dilakukan sesuai kebijakan retur."
        //
        // true (default) = barang yang diretur kembali ke stok lewat mutasi
        //   `sale_return` sebesar yang pernah dikurangi saat penjualan;
        // false = retur hanya mencatat koreksi nilai & uang, stok dibiarkan
        //   (mis. barang rusak atau tidak kembali ke rak).
        'restore_stock_on_return' => (bool) env('POS_RESTORE_STOCK_ON_RETURN', true),
    ],

    'receivables' => [
        // FR-07: "Tanggal jatuh tempo." Penjualan kredit tanpa `due_at`
        // eksplisit dari pemanggil memakai tenggat default ini (hari).
        // 0 / negatif = tidak menetapkan tenggat.
        'default_due_days' => (int) env('POS_RECEIVABLE_DUE_DAYS', 30),

        // FR-07: "Batas kredit dan peringatan pelanggan dapat dikonfigurasi per
        // toko."
        //
        // false (default) = hanya DIPERINGATKAN: penjualan kredit yang melewati
        //   batas tetap disimpan, dan UI menampilkan peringatan agar kasir
        //   memutuskan sadar;
        // true = DIBATASI: penjualan kredit yang membuat total piutang pelanggan
        //   melewati `customers.credit_limit` ditolak (`CreditLimitExceeded`).
        //
        // Pelanggan dengan `credit_limit` 0 (atau kosong) dianggap tanpa batas.
        'enforce_credit_limit' => (bool) env('POS_ENFORCE_CREDIT_LIMIT', false),
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

    /*
    |--------------------------------------------------------------------------
    | API untuk klien perangkat (§18)
    |--------------------------------------------------------------------------
    |
    | Autentikasi server memakai mekanisme yang didukung Laravel (§15):
    | Sanctum (token per pengguna) untuk identitas MANUSIA, sedangkan
    | identitas PERANGKAT tetap token di tabel `devices` (§12, §15: "Perangkat
    | memiliki ID dan token unik") yang dipakai endpoint sinkronisasi.
    |
    | Token pengguna dibatasi masa berlakunya — token yang bocor tidak berlaku
    | selamanya. Nilai 0 berarti tanpa kedaluwarsa (tidak disarankan).
    |
    */

    'api' => [
        'token_days' => (int) env('POS_API_TOKEN_DAYS', 30),
        // Pembatasan percobaan login (per menit, per alamat IP) — NFR-03.
        'login_attempts_per_minute' => (int) env('POS_API_LOGIN_ATTEMPTS', 10),
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
        // Batas halaman tarik perubahan dalam satu putaran (§11.3 langkah 9,
        // §11.5): perangkat yang lama offline menyusul bertahap, dan satu
        // putaran tidak berjalan tanpa batas.
        'max_pull_pages' => (int) env('POS_SYNC_MAX_PULL_PAGES', 20),
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

    /*
    |--------------------------------------------------------------------------
    | Ambang alert lokal (FR-10)
    |--------------------------------------------------------------------------
    |
    | Alert berbasis kondisi lokal: ambang toleransi ditentukan di sini agar
    | tidak tersebar sebagai angka ajaib, dan dapat disesuaikan per toko tanpa
    | mengubah kode. Alert hanya dibuat bila kondisi bertahan melewati ambang —
    | supaya satu keterlambatan sesaat tidak memicu notifikasi.
    |
    */

    'alerts' => [
        // Operasi outbox yang belum terkirim lebih lama dari ini (menit)
        // memicu "sinkronisasi tertunda" (§11.5).
        'sync_backlog_minutes' => (int) env('POS_ALERTS_SYNC_BACKLOG_MINUTES', 30),
        // Perangkat aktif yang tidak menyapa server lebih lama dari ini (jam)
        // dianggap belum tersinkron.
        'device_stale_hours' => (int) env('POS_ALERTS_DEVICE_STALE_HOURS', 24),
        // Transaksi QRIS/gateway yang masih "menunggu verifikasi" lebih lama
        // dari ini (menit) perlu diperiksa kasir (FR-05, §14).
        'payment_verification_minutes' => (int) env('POS_ALERTS_PAYMENT_VERIFICATION_MINUTES', 30),
        // Rentang pemeriksaan selisih stok hasil opname (hari).
        'stock_discrepancy_days' => (int) env('POS_ALERTS_STOCK_DISCREPANCY_DAYS', 7),
    ],

];
