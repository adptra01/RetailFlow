# Rebuild POS Universal — Fase 4: Konflik stok (Sprint 4, item 7) (belum dirilis)

**Tanggal:** 11 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 4 item 7 "Konflik stok", §11.3 langkah 11, §11.4, §11.5, §26 "Konflik stok tidak disembunyikan").

## Perubahan

### Kebijakan stok negatif (§11.4)
- **`config/pos.php` → `inventory.allow_negative_stock`** (env `POS_ALLOW_NEGATIVE_STOCK`, default `false`).
  - `false` = **batasi penjualan**: operasi perangkat yang membuat stok negatif tidak diterapkan; dibuat kasus konflik terbuka.
  - `true` = **izinkan stok negatif**: penjualan diterapkan apa adanya; konflik tetap dicatat agar tidak disembunyikan.
- **`CreateSale`** menerima `allow_negative_stock`; bila aktif, `assertStock()` tidak lagi membatasi nol, namun `StockMovement` tetap mencatat `after_stock` negatif (ledger tetap otoritatif).

### Kasus konflik sebagai entitas
- **Migration `2026_10_10_000020_create_sync_conflicts_table.php`** — tabel `sync_conflicts` (`uuid`, `tenant_id`, `device_id`, `operation_id`, `type`, `status`, `entity_type/entity_id`, `details`, `resolution`, `resolved_by`, `resolved_at`, `version`). Unik `(operation_id, type)` → idempoten. Perluasan §17.2 yang dibenarkan oleh §11.3 langkah 11 + §11.4.
- **`App\\Enums\\SyncConflictType`** (`stock`) dan **`App\\Enums\\SyncConflictStatus`** (`open`/`resolved`/`dismissed`).
- **`App\\Models\\SyncConflict`** — `BelongsToTenant` + `HasSyncIdentity` + `JournalSyncChanges` (masuk `sync_changes` sehingga perangkat dapat menarik & menampilkan konflik), scope `open()`/`ofType()`.

### Deteksi & pencatatan
- **`App\\Actions\\Sync\\RecordSyncConflict`** — `updateOrCreate` per `(operation_id, type)`; pengiriman ulang tidak menggandakan kasus.
- **`SaleSyncHandler`** — pada `InsufficientStock`: catat kasus (`reason: insufficient_stock`, `available`, `requested`, `shortfall`) dan kembalikan `conflict` (penjualan tidak diterapkan, stok utuh). Bila kebijakan mengizinkan, penjualan diterapkan lalu stok negatif dideteksi pasca-terap dan dicatat (`reason: negative_stock_applied`, `products[].after_stock`).
- Penjualan perangkat **tidak pernah dihapus** — baris `sync_inbox` tetap ada pada status `conflict` (§11.4, §11.5).

### Penyelesaian (§11.4 "kasus yang perlu diselesaikan")
- **`App\\Actions\\Sync\\ResolveSyncConflict`** — hanya pengguna berizin `stock.adjust` (`ActionAuthorizer::assertIf`, FR-12):
  - `allow()` — menerapkan ulang penjualan tertahan dengan stok negatif diizinkan; kasus → `resolved`; baris inbox → `applied`.
  - `dismiss()` — menutup kasus tanpa penerapan; baris inbox tetap `conflict` (data tidak dihapus).
  - Keduanya menulis `resolution` (`action`, `note`, `transaction_uuid`) + `resolved_by`/`resolved_at` sebagai jejak audit; berjalan dalam transaksi dengan `lockForUpdate`.

### Laporan (§11.4 "membedakan stok fisik yang diperkirakan dan stok tersinkron")
- **`App\\Reports\\StockSyncReport`** — per produk: `stock_tersinkron` (ledger server), `mutasi_tertunda` (unit bersih dari operasi penjualan inbox `received`/`conflict`), `stock_diperkirakan = stock_tersinkron − mutasi_tertunda`, `konflik_terbuka`; ringkasan `kasus_konflik_terbuka` & `operasi_tertunda`; `openConflicts()`; `metricDefinitions()`. Bila `$actor` diberi, izin `report.financial.view` diperiksa (FR-12).

## Test (`tests/Feature/Sync/StockConflictTest.php`, 12)
- Stok kurang → **kasus konflik terbuka** dengan detail benar, tanpa transaksi, stok utuh.
- Pengiriman ulang **tidak menggandakan** kasus; kasus **dijurnal** untuk sinkronisasi perangkat.
- Kebijakan izinkan stok negatif → penjualan **diterapkan** (stok −4) **dan** konflik dicatat.
- `allow()` → penjualan tertahan diterapkan (stok negatif), kasus `resolved`, inbox `applied`.
- Perizinan: tanpa `stock.adjust` → `ActionForbidden`; peran Owner dapat menyelesaikan.
- `dismiss()` → kasus `dismissed`, inbox tetap `conflict`, tidak ada transaksi, stok utuh.
- Laporan membedakan stok tersinkron/diperkirakan + daftar konflik terbuka; izin laporan ditegakkan; setelah penyelesaian tidak lagi dihitung tertunda.

## Status suite
- **340 passed / 0 failed / 6 skipped** (1131 assertions). Pint bersih.

---

# Rebuild POS Universal — Fase 4: Idempotensi server (Sprint 4, item 6) (belum dirilis)

**Tanggal:** 11 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 4 item 6 "Idempotensi server", §11.1 "aman terhadap pengiriman ulang" + "Operasi yang dapat diulang harus idempoten", §11.3 langkah 4–7, §11.4 konflik stok, §11.5 penolakan/konflik, §12 identitas dari server).

## Perubahan

### Penerapan domain
- **`App\Support\Sync\SyncOperationHandler`** (kontrak) — menerjemahkan satu operasi inbox menjadi tindakan domain; implementasi **wajib idempoten**.
- **`App\Support\Sync\Handlers\SaleSyncHandler`** — `sale` + `create` → `CreateSale`, memakai **`operation_id` sebagai `client_request_id`** sehingga penerapan ulang mengembalikan penjualan yang sama. `tenant_id`/`device_id` selalu diambil dari baris inbox (identitas server), bukan payload (§12). Stok kurang → `conflict` (§11.4); pelanggaran aturan lain → `rejected` (§11.5).
- **`App\Support\Sync\SyncOperationApplier`** — registri handler; operasi tanpa handler dibiarkan `received` (durable, menunggu) alih-alih ditolak (data tidak dibuang).
- **`App\Support\Sync\SyncApplicationResult`** — hasil: `applied` / `rejected` / `conflict` / `deferred`.
- **`App\Actions\Sync\ApplySyncOperations`** — menerapkan baris `received` (opsional dibatasi `operation_ids`), **satu transaksi per operasi** dengan `lockForUpdate` dan pemeriksaan status ulang, sehingga penerapan ganda/paralel tidak menggandakan efek domain. Mengembalikan status akhir per `operation_id`.

### Skema & model
- **Migration `2026_10_10_000019_add_application_columns_to_sync_inbox_table.php`** — `applied_at`, `applied_entity_id` (aditif; indeks `(tenant_id,status)` sudah ada dari item 3).
- **`SyncInbox`** — `isReceived()`, `markApplied()`, `markRejected()`, `markConflict()`.

### Alur push
- **`SyncPushController`** — setelah penerimaan, menerapkan operasi lalu mengembalikan status **akhir** per operasi: `applied`/`rejected`/`conflict`/`received`, plus `duplicate` (boolean), `entity_id`, `error`.
- **`ReceiveSyncPush`** — pemasukan **aman-balapan**: `operation_id` unik; bila insert kalah balapan (pengiriman ulang paralel) → diperlakukan sebagai duplikat, bukan error.
- **`PushOutbox`** — status `applied` juga dihitung `synced` (§11.5).
- **`AppServiceProvider`** — binding singleton `SyncOperationApplier` dengan daftar handler default.

## Test (`tests/Feature/Sync/ServerIdempotencyTest.php`, 8)
- Penjualan push **diterapkan** ke domain (transaksi + stok turun + inbox `applied` + `applied_entity_id`).
- **Pengiriman ulang tidak menggandakan** penjualan maupun mutasi stok (1 transaksi, 1 mutasi, stok tetap).
- Penerapan kedua (`ApplySyncOperations`) tetap idempoten.
- **Stok kurang → konflik** tanpa efek samping (tidak ada transaksi, stok utuh).
- Penjualan tidak valid → **rejected** (mis. tanpa `user_id`).
- Operasi tanpa handler → **deferred** (`received`); batch mencampur applied + deferred.
- Status terisolasi per perangkat.
- `SyncApiTest` disesuaikan ke kontrak baru: `results.*.status` = status akhir, `results.*.duplicate` menandai pengiriman ulang (2 test diperbarui memakai entity netral `product`).

## Catatan
- Penerapan dijalankan **sinkron** dalam permintaan push sehingga perangkat menerima hasil definitif. `ApplySyncOperations` juga dapat dipanggil ulang (mis. job/command) untuk operasi yang masih `received` (deferred).
- Batas antara `rejected` (butuh tindakan pengguna) dan `conflict` (kasus yang perlu diselesaikan) mengikuti §11.4/§11.5; pendalaman deteksi stok negatif/ketidaksesuaian adalah item Sprint 4.7 "Konflik stok".

## Status suite
- **328 passed / 0 failed / 6 skipped** (1067 assertions). Pint bersih.

---

# Rebuild POS Universal — Fase 4: Retry dengan backoff (Sprint 4, item 5) (belum dirilis)

**Tanggal:** 11 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 4 item 5 "Retry dengan backoff", §11.1 "Operasi yang dapat diulang harus idempoten", §11.3 "Pengiriman ulang setelah timeout harus aman…", §11.5 "Sinkronisasi tertunda atau gagal" + batch terbatas, §13 tahan crash, §18 push).

## Perubahan

### Backoff
- **`App\Support\Sync\Backoff`** — jadwal jeda dari `config('pos.sync.retry_backoff')` `[1,5,10]`; percobaan pertama (`retry_count = 0`) tanpa jeda; nilai terakhir menjadi batas atas; jadwal kosong = tanpa jeda.

### Transport push
- **`App\Support\Sync\SyncTransport`** (interface) + **`HttpSyncTransport`** — `POST {server}/api/v1/sync/push` dengan `Authorization: Bearer` (§18). Koneksi/timeout/5xx → **`App\Exceptions\Sync\SyncTransportException`** (hasil **tidak diketahui**, bukan kegagalan permanen).
- Binding `SyncTransport` → `HttpSyncTransport` di `AppServiceProvider`. Seam transport memisahkan protokol dari loop retry sehingga dapat diuji tanpa jaringan.

### Antrean & model
- **`SyncOutbox`**: `markRetryPending($error)` (kembali `pending`, `retry_count++` — dipakai saat hasil tidak diketahui), `nextAttemptAt()` (waktu dasar `updated_at` + jeda backoff), `isReadyForAttempt()`.
- **`Outbox`**: `readyForAttempt($deviceId, $limit, $now)` (retryable + backoff lewat) dan `resetStaleInFlight($deviceId, $timeout, $now)` (pulihkan `in_flight` terbengkalai akibat crash, §13).
- Config baru: `pos.sync.server_url`, `pos.sync.request_timeout` (10), `pos.sync.in_flight_timeout` (300).

### Loop pengiriman
- **`App\Actions\Sync\PushOutbox`** — satu putaran: pulihkan `in_flight` basi → pilih entri siap (batas batch) → tandai `in_flight` → kirim satu batch → terapkan hasil per operasi.
  - `received`/`synced`/`duplicate` → `synced`.
  - `rejected`/`invalid` → `failed` (+pesan).
  - `conflict` → `conflict`.
  - status tak dikenal / hasil tak lengkap / transport gagal → **`pending` + `retry_count++`** (diulang dengan backoff), data lokal dipertahankan.
- **`App\Support\Sync\SyncPushSummary`** — ringkasan `sent/synced/failed/conflicted/retrying` untuk status UI (item 8).

## Test
- **`tests/Feature/Sync/RetryBackoffTest.php`** (14) + **`tests/Feature/Sync/FakeSyncTransport.php`**: push sukses; duplikat = synced; kegagalan transport tetap retryable + data utuh; backoff menunda percobaan berikutnya; retry habis tidak diulang; **pengiriman ulang setelah timeout aman** (gagal → sukses, tanpa duplikasi); penolakan server → `failed`; konflik → `conflict`; status tak dikenal → retry; batas batch; **`in_flight` basi dipulihkan** & `in_flight` baru dibiarkan; `HttpSyncTransport` mengirim ke endpoint dengan bearer + menaikkan exception pada 5xx.
- **`tests/Unit/BackoffTest.php`** (4): tanpa jeda, mengikuti jadwal, batas atas, jadwal kosong.

## Catatan
- Status `pending` dipakai untuk hasil tak diketahui (bukan `failed`) sesuai §11.3. Bila `retry_count` mencapai `max_retries`, entri berhenti diulang namun tetap tersimpan dan terhitung pada `pendingCount` (data lokal tidak dihapus).
- `failed` = penolakan domain server (butuh tindakan pengguna); pemisahan lebih lanjut antara penolakan permanen vs. konflik akan disempurnakan pada item "Deteksi konflik".
- Backoff memakai `updated_at` sebagai penanda waktu percobaan terakhir (satu-satunya mutasi baris outbox adalah transisi status) sehingga tidak perlu kolom baru.

## Status suite
- **320 passed / 0 failed / 6 skipped** (1023 assertions). Pint bersih.

---

# Rebuild POS Universal — Fase 4: Cursor sinkronisasi (Sprint 4, item 4) (belum dirilis)

**Tanggal:** 11 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 4 item 4 "Cursor sinkronisasi", §11.1 prinsip, §11.3 langkah 9–10, §12 "version atau cursor yang sesuai", §17 tabel, §18 `GET /api/v1/sync/pull?cursor=...`).

## Perubahan

### Jurnal perubahan sisi server (`sync_changes`)
- **Migration `2026_10_10_000018_create_sync_changes_table.php`** — append-only: `sequence` (bigint naik = **kursor**), `tenant_id`, `entity_type`, `entity_id`, `operation`, `payload` (snapshot JSON), `device_id` (asal perubahan; null = server/web), `created_at`. Indeks `(tenant_id, sequence)` & `(entity_type, entity_id)`.
  - Kursor berupa `sequence` monoton menjamin urutan total tanpa bergantung pada resolusi `updated_at` — memenuhi §12 "version atau cursor yang sesuai".
- **`App\Enums\SyncChangeOperation`** — `created`, `updated`, `deleted`.
- **`App\Models\SyncChange`** — PK `sequence`; cast enum/array; relasi `tenant`/`device`; scope `forTenant`/`afterSequence`; tanpa `updated_at` (append-only).

### Perekaman perubahan
- **`App\Support\Sync\SyncChangeJournal`** — menulis satu baris `sync_changes` per perubahan. Tenant diambil dari record lalu `TenantContext`; `device_id` dari `DeviceContext`. Payload memakai `attributesToArray()` sehingga cast diterapkan dan atribut `$hidden` (mis. `password`) dikecualikan. Perubahan tanpa tenant (seeder/CLI) **dilewati** karena `sync_changes.tenant_id` wajib dan tidak ada tenant tujuan.
- **`App\Models\Concerns\JournalSyncChanges`** — menulis jurnal pada event `created`/`updated`/`deleted`. Diterapkan pada model bisnis: **Product, Category, Customer, Transaction, TransactionItem, StockMovement, Receivable, ReceivablePayment**. Karena berjalan di dalam transaksi domain, baris jurnal ikut atomik (rollback domain → jurnal batal).

### Feed kursor
- **`App\Support\Sync\DatabaseSyncChangeFeed implements SyncChangeFeed`** — mengembalikan perubahan tenant dengan `sequence > cursor`, urut naik, dibatasi `limit`; `has_more` diisi bila masih ada lanjutan; `cursor` = `sequence` terakhir yang dikirim (atau kursor lama bila tidak ada perubahan).
- Binding `SyncChangeFeed` di `AppServiceProvider` dialihkan dari `NullSyncChangeFeed` → `DatabaseSyncChangeFeed` (adaptor null tetap tersedia sebagai fallback/uji).
- `SyncPullController` kini mengembalikan perubahan nyata dan memperbarui `devices.last_sync_cursor` bila kursor berubah.

## Test (`tests/Feature/Sync/CursorTest.php`, 13 test)
- Jurnal: create/update/delete tercatat dengan entity/uuid/tenant/payload; record tanpa tenant dilewati; **atomik terhadap rollback transaksi**; `device_id` asal; payload mengecualikan atribut `$hidden`.
- Feed: urut `sequence`; hanya setelah kursor; **paginasi tanpa terlewat/ganda**; isolasi tenant; melempar tanpa tenant.
- Endpoint: pull mengembalikan perubahan nyata + menyimpan `last_sync_cursor`, pull ulang dengan kursor sama tidak mengulang; paginasi `limit` + `has_more`.

## Catatan

- `version` pada record **tidak** di-bump pada item ini (kolom `version` sudah ada, tetapi belum bermakna). Penambahan perilaku versi dan deteksi konflik adalah lingkup item Sprint 4 berikutnya ("Deteksi konflik"); kolom `version` sengaja tidak dimasukkan ke jurnal agar tidak menyajikan data menyesatkan.
- Perubahan domain yang **diterapkan dari operasi push** belum terjadi (operasi `sync_inbox` masih `received`); belum ada baris jurnal yang berasal dari penerapan push.
- Perangkat belum memfilter perubahan miliknya sendiri (echo aman karena penerapan bersifat idempoten).

## Status suite
- **302 passed / 0 failed / 6 skipped** (972 assertions). Pint bersih pada seluruh berkas baru/berubah.

---

# Rebuild POS Universal — Fase 4: API push/pull (Sprint 4, item 3) (belum dirilis)

**Tanggal:** 11 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 4 item 3 "API push/pull", §11.1 prinsip sinkronisasi, §11.3 proses langkah 4–7, §12 verifikasi tenant, §15 kredensial perangkat, §18 endpoint `POST /api/v1/sync/push` & `GET /api/v1/sync/pull`, §17 tabel).

## Perubahan

### Transport & autentikasi perangkat
- **`routes/api.php`** — API berversi `/api/v1`: `POST sync/push`, `GET sync/pull`; keduanya di bawah middleware `device`.
- **`bootstrap/app.php`** — mendaftarkan `routes/api.php` sebagai grup `api` dan alias middleware `device`.
- **`App\Http\Middleware\AuthenticateDevice`** — membaca token dari `Authorization: Bearer` atau `X-Device-Token`, memverifikasi lewat `Device::findByToken` (perangkat dicabut/tak dikenal → 401), mengikat `TenantContext` + `DeviceContext`, dan menandai heartbeat (`last_seen_at`, `X-App-Version` → `app_version`).
  - **Tenant diambil dari perangkat terautentikasi, bukan body request** (§12: "Jangan mengandalkan `tenant_id` dari request pengguna tanpa memverifikasinya").

### Inbox sisi server (`sync_inbox`)
- **Migration `2026_10_10_000017_create_sync_inbox_table.php`** — cermin sisi server dari outbox: `id` (bigint naik = urutan server monoton), `operation_id` (uuid **unik**), `tenant_id`, `device_id`, `entity_type`, `entity_id`, `operation_type`, `payload`, `local_sequence`, `status`, `error`, `received_at`, timestamps.
  - **Keputusan**: tabel ini tidak disebut eksplisit di §17 (yang hanya memuat `sync_outbox` sisi klien), tetapi diperlukan untuk memenuhi §11.3 langkah 6 ("Server menyimpan operasi yang sah dalam transaksi") dan §11.1 ("aman terhadap pengiriman ulang"). Bersifat aditif dan tidak mengubah tabel inti.
- **`App\Enums\SyncInboxStatus`** — `received` (default), `applied`, `rejected`, `conflict`.
- **`App\Models\SyncInbox`** — casts enum/array, relasi `tenant`/`device`, scope `forDevice`/`withStatus`, `serverSequence()`.

### Use case & endpoint
- **`App\Actions\Sync\ReceiveSyncPush`** — menyimpan batch operasi dalam **satu transaksi**; `operation_id` unik membuat pengiriman ulang idempoten (operasi yang sudah ada → `duplicate`, tanpa baris baru); `tenant_id`/`device_id` dari identitas terautentikasi.
- **`App\Http\Requests\Sync\SyncPushRequest`** — validasi batch (`operations` array 1..`pos.sync.max_batch`, `operation_id` uuid + `distinct`, `operation_type` ∈ `create|update|delete`, `local_sequence` ≥ 1).
- **`App\Http\Requests\Sync\SyncPullRequest`** — `cursor` opsional, `limit` opsional (1..500).
- **`App\Http\Controllers\Api\V1\SyncPushController`** — mengembalikan `accepted`, `duplicates`, dan `results` per operasi (langkah 7).
- **`App\Http\Controllers\Api\V1\SyncPullController`** — mengembalikan envelope `{device_id, tenant_id, server_time, cursor, has_more, changes}`; menyimpan `last_sync_cursor` perangkat bila kursor berubah.

### Seam sumber perubahan (`SyncChangeFeed`)
- **`App\Support\Sync\SyncChangeFeed`** (kontrak) + **`SyncChangeBatch`** (DTO readonly) + **`NullSyncChangeFeed`** (adaptor default, mengembalikan perubahan kosong).
- Di-bind di `AppServiceProvider`; implementasi kursor sebenarnya menjadi lingkup item Sprint 4 berikutnya. Pola ini konsisten dengan keputusan seam printer (port + adaptor null) agar endpoint tetap dapat diuji tanpa mengarang semantik kursor.

### Konfigurasi
- `config/pos.php` `sync.max_batch` (env `POS_SYNC_MAX_BATCH`, default 500).

## Test (`tests/Feature/Sync/SyncApiTest.php`, 15 test)
- Push: 401 tanpa token / token tak dikenal / perangkat dicabut; penyimpanan operasi dengan tenant+perangkat terautentikasi; **identitas payload diabaikan**; idempotensi `operation_id` (duplikat → `duplicate`, tetap 1 baris); validasi bentuk batch (kosong, `operation_type` tak dikenal, `operation_id` bukan uuid, duplikat dalam batch, melebihi `max_batch`); heartbeat + `app_version`; isolasi antar-tenant/perangkat.
- Pull: 401 tanpa token; envelope kursor; `cursor`/`limit` (validasi batas); tenant dari perangkat, bukan query parameter.

## Status suite
- **289 passed / 0 failed / 6 skipped** (934 assertions). Pint bersih pada seluruh berkas baru/berubah.

## Catatan
- Operasi pada `sync_inbox` belum **diterapkan ke domain** (masih `received`); penerapan, idempotensi penerapan, dan deteksi konflik stok adalah lingkup item Sprint 4 berikutnya.
- `SyncChangeFeed` masih adaptor kosong sampai item "Cursor sinkronisasi".

---

# Rebuild POS Universal — Fase 4: Outbox sinkronisasi (Sprint 4, item 2) (belum dirilis)

**Tanggal:** 11 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 4 item 2 \"Outbox\", §11.1 prinsip offline, §11.2 struktur outbox, §11.3 proses, §11.5 perangkat lama offline, §13 event/transactional outbox, §17 tabel `sync_outbox`).

## Perubahan

### Skema & model
- **Migration `2026_10_10_000016_create_sync_outbox_table.php`** — `sync_outbox` dengan `operation_id` (uuid PK), `tenant_id`, `device_id`, `entity_type`, `entity_id`, `operation_type`, `payload` (json), `local_sequence`, `sync_status`, `retry_count`, `last_error`, timestamps. Unik `(device_id, local_sequence)`; indeks `(tenant_id, sync_status)` & `(device_id, sync_status)`.
- **`App\Enums\SyncStatus`** — `pending`, `in_flight`, `synced`, `conflict`, `failed` (+label, `isTerminal`, `retryableValues`).
- **`App\Enums\SyncOperationType`** — `create`, `update`, `delete`.
- **`App\Models\SyncOutbox`** — PK `operation_id` (HasUuids); casts enum/array; relasi `tenant`/`device`; scope `forDevice`/`pending`/`retryable`; transisi `markInFlight`/`markSynced`/`markConflict`/`markFailed` (menambah `retry_count`) + `isRetryable`.

### Layanan (`app/Support/Sync/Outbox.php`)
- `enqueue(deviceId, entityType, entityId, operationType, payload, operationId?)` — menulis baris `pending`; **dirancang dipanggil di dalam transaksi domain** (transactional outbox, §13) sehingga rollback domain membatalkan entri outbox.
- `nextSequence(deviceId)` — urutan lokal per perangkat (mulai 1), atomik + dilindungi unique index.
- `pending()` / `retryable()` — batch urut `local_sequence`, ukuran dari `pos.offline.batch_size`.
- `pendingCount()` / `unsyncedCount()` — jumlah operasi tertunda (§11.5).
- Resolusi tenant: eksplisit → `TenantContext`; melempar bila tak diketahui.

## Test (`tests/Feature/Sync/OutboxTest.php`, 12 test)
- Enqueue menyimpan `pending` + sequence; sequence independen per perangkat.
- **Transactionality**: entri hilang bila transaksi domain rollback; bertahan bila commit.
- Urutan `pending`, batas batch, transisi status, `pendingCount` turun setelah `synced`.
- `markFailed` menaikkan `retry_count` + `last_error`; `retryable` berhenti saat `pos.sync.max_retries` tercapai.
- `markConflict`, payload round-trip, `operation_id` unik, resolusi tenant & galat tanpa tenant.

## Perbaikan test (flaky)
- `tests/Feature/Reports/SalesReportTest::test_riwayat_lists_transactions_newest_first` — **flaky saat test berjalan lewat tengah malam**: transaksi dibuat `now()->subHours(2)` sementara `SalesReport::range()` memfilter hari ini 00:00–23:59, sehingga keduanya jatuh ke hari sebelumnya. Waktu dikunci ke tengah hari dengan `travelTo()`. Bukan perubahan perilaku aplikasi.

## Catatan

- Outbox belum dikonsumsi oleh alur domain (integrasi enqueue pada use case + API push/pull menyusul pada item Sprint 4 berikutnya).
- Cursor sinkronisasi, retry/backoff pengiriman, dan idempotensi server belum dibangun.

---

# Rebuild POS Universal — Fase 4: Identitas perangkat (Sprint 4, item 1) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 4 item 1 \"Identitas perangkat\", NFR-03 \"Keamanan\", §11 \"Strategi sinkronisasi\", §15 \"Arsitektur keamanan\", §17 tabel `devices`).

## Perubahan

### Kredensial perangkat
- **Migration `2026_10_10_000015_add_device_credentials_to_devices_table.php`** — menambah `token_hash` (string 64, nullable, unik) dan `token_prefix` (16) ke `devices`; additive, tidak merusak data lama.
- **`app/Support/Devices/DeviceToken.php`** — generate token `rf_<48 char>`; hanya **hash SHA-256** yang disimpan (token berentropi tinggi; KDF lambat tidak diperlukan), `prefix` disimpan untuk ditampilkan.
- **`app/Support/Devices/DeviceRegistration.php`** — VO hasil pendaftaran (`device`, `token` plaintext sekali).
- **`app/Support/Devices/DeviceContext.php`** — holder request-scoped perangkat (mirror `TenantContext`), di-bind singleton.
- **`Device`** model: `token_hash`/`token_prefix` fillable, `token_hash` di-`hidden`; `findByToken()` (hanya perangkat aktif, menolak yang dicabut), `markSeen()`.
- **`app/Actions/Devices/RegisterDevice.php`** — daftarkan perangkat + terbitkan token; audit `device.registered`.
- **`app/Actions/Devices/RevokeDevice.php`** — cabut akses (idempoten); audit `device.revoked`. Exception `DeviceNotFound` (404).
- Otorisasi: izin administratif FR-12 `user.manage` bila aktor diberikan (`assertIf`); pendaftaran saat onboarding/CLI tanpa aktor diizinkan.

### Audit
- `AuditRecorder` kini mengisi `device_id` dari `DeviceContext` bila tidak diberikan eksplisit (menutup celah `device_id` selalu null).
- `AuditAction` diperluas: `device.registered`, `device.revoked`.

## Test (`tests/Feature/Devices/DeviceIdentityTest.php`, 10 test)
- Token diterbitkan & hanya hash disimpan; plaintext tak pernah terserialisasi.
- `findByToken` mengenali perangkat aktif; token salah → null; perangkat dicabut **ditolak** (dicabut idempoten).
- Izin `user.manage` wajib saat aktor diberikan (kasir ditolak); pendaftaran onboarding tanpa aktor diizinkan.
- Perangkat tenant lain / tidak dikenal → `DeviceNotFound`.
- `AuditRecorder` memakai `DeviceContext`; `markSeen()` memperbarui `last_seen_at` + versi.

## Catatan

- Handshake HTTP/API perangkat (endpoint + middleware) menyusul pada item Sprint 4 berikutnya (outbox, API push/pull).
- Pencabutan bersifat online: perangkat lama offline baru tertolak pada sinkronisasi berikutnya (§15).

---

# Rebuild POS Universal — Fase 3: Uji restart/recovery & dokumentasi dukungan (Sprint 3) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 3 item 9 \"Uji restart dan recovery\", item 10 \"Dokumentasikan prosedur dukungan\", §23 Observability & diagnostik, Fase 0 \"Aplikasi tetap dapat membuka data setelah restart\", §17.3 atomisitas).

## Perubahan

### Uji restart (`tests/Feature/Recovery/DatabaseRestartTest.php`)
- Memakai SQLite **berbasis file** (bukan `:memory:`) sehingga `DB::purge()` + `DB::reconnect()` benar-benar menirukan proses berhenti lalu hidup kembali.
- Data operasional (transaksi, item, stok, ledger, audit) bertahan setelah restart, dan **saldo stok == stok awal + Σ ledger** (rekonsiliasi).
- Checkout yang sudah committed **aman diulang** setelah restart (`client_request_id` idempoten) — tidak ada transaksi/ledger ganda dan stok tidak berkurang dua kali.

### Uji recovery (`tests/Feature/Recovery/RecoveryTest.php`)
- Checkout gagal (stok tidak cukup) **tidak meninggalkan data parsial** (0 transaksi/item/ledger; stok utuh).
- Percobaan ulang setelah gagal berhasil bersih (tepat 1 entri ledger).
- Rekonsiliasi saldo stok dengan ledger setelah beberapa penjualan.

### Dokumentasi dukungan (`docs/SUPPORT.md`, `README.md`)
- Runbook §23: informasi diagnostik yang harus dikumpulkan, prosedur pemulihan (restart, checkout gagal, printer gagal, restore backup), peran/izin FR-12, alert FR-10, ekskalasi, dan privasi log.
- `README.md` diperluas: tautan dokumentasi + daftar perintah operasional.

## Catatan

- **Sprint 3 selesai** (item 1–10): impor CSV, laporan harian, alert stok minimum, backup & restore, peran pengguna, audit tindakan sensitif, profil Simple/Retail, uji kegagalan printer, uji restart/recovery, dokumentasi dukungan.
- Fase 4 (offline sync foundation) belum dimulai; item diagnostik \"sinkronisasi terakhir / operasi tertunda\" sengaja ditandai *belum dibangun*.

---

# Rebuild POS Universal — Fase 3: Seam printer & uji kegagalan (Sprint 3) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Sprint 3 \"Uji kegagalan printer\", §17.3 \"Commit … kegagalan printer tidak boleh membatalkan penjualan\", §20 \"Printer\", §22 \"Pengujian kegagalan wajib: Printer gagal setelah checkout berhasil\", §254 \"Penjualan yang tersimpan tidak boleh dibatalkan hanya karena printer gagal\").

## Perubahan

### Port pencetakan (`app/Printing/`)
- **`ReceiptPrinter`** — port (interface) `print(Transaction): void`; implementasi konkret (ESC/POS/driver OS) menyusul di lapisan infrastruktur. Domain tidak bergantung pada driver printer (§634).
- **`NullReceiptPrinter`** — adaptor default no-op; di-bind di `AppServiceProvider` (printer opsional: \"Printer tidak boleh menjadi syarat untuk menyimpan transaksi\").
- **`PrintReceiptResult`** — VO readonly hasil pencetakan (`success`, `transaction`, `error`).
- **`PrinterException`** — kegagalan pencetakan (operasional, bukan fatal).

### Use case cetak (`app/Actions/Sales/PrintReceipt.php`)
- Mencetak **setelah** transaksi committed; **tidak pernah** me-rollback penjualan.
- Kegagalan `PrinterException` → `Log::warning` + audit `receipt.print_failed` (aktor, `entity_type=sale`, `entity_id=uuid`, `reason`) lalu mengembalikan `PrintReceiptResult::failed(...)` agar kasir dapat mencetak ulang / menyimpan struk.
- Exception lain **tidak** ditelan (bug tetap terlihat).
- `AuditAction::ReceiptPrintFailed` ditambahkan.

## Test (`tests/Feature/Printing/PrintReceiptTest.php`, 5 test)
- Adaptor default = `NullReceiptPrinter`, selalu sukses, tanpa audit kegagalan.
- Cetak sukses memanggil printer, tanpa audit kegagalan.
- Cetak gagal → hasil `failed` + audit aktor + transaksi tetap ada.
- **Uji kegagalan wajib**: checkout committed → printer gagal → transaksi, item, dan stok **tidak** berubah/rollback.
- Penjualan tersimpan tanpa pernah menyentuh printer.

## Catatan

- Ini menyediakan *seam* minimal agar uji kegagalan printer (Sprint 3) bermakna; driver thermal nyata (58/80 mm) masuk pada fase perangkat keras.
- Belum ada alert lokal untuk kegagalan printer (kandidat FR-10 berikutnya).

---

# Rebuild POS Universal — Fase 3: Profil Simple/Retail (FR-13) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 3 §24 "Profil Simple/Retail", Sprint 3 "Profil Simple dan Retail", FR-13 §430–447, unit test §22 "Aturan profil fitur")

## Perubahan

### Katalog modul
- **`app/Enums/Module.php`** — 15 modul dengan label Indonesia (`cashier`, `products`, `history`, `stock`, `purchases`, `suppliers`, `customers`, `receivables`, `promotions`, `reports`, `reports_advanced`, `menu`, `orders`, `audit`, `sync`).
- **`FeatureProfile::modules()`** diselaraskan dengan FR-13: **Retail** kini menyembunyikan→menampilkan `history`, `suppliers` ("menampilkan stok, pemasok, dan pembelian"); **Advanced** melengkapi `reports_advanced`, `suppliers`, `menu`, `orders` (tetap superset Semua profil Simple). Simple tetap menyembunyikan `purchases` & `reports_advanced`.

### Aturan resolusi (`app/Support/Features/FeatureProfileManager.php`)
- Modul efektif tenant: **(1)** pakai `enabled_modules` eksplisit bila terisi (override per-tenant hasil toggle), **(2)** jika null/kosong → default `feature_profile`. Menyaring nilai tak dikenal, deduplikasi, mengembalikan `Module` bertipe.
- `for(Tenant)` / `resolve(profile, enabledModules)` / `isEnabled` / `has` / `moduleNames`.
- Resolusi murni **visibilitas** — bukan otorisasi (izin FR-12 tetap terpisah).

### Aksi teraudit (`app/Actions/Tenants/ConfigureFeatureProfile.php`)
- `applyProfile(actor, profile, reason)` — set `feature_profile` + setel ulang `enabled_modules` ke default profil.
- `enableModule` / `disableModule` — toggle `enabled_modules` saja; **idempoten** (tanpa perubahan = tanpa jejak audit baru).
- `configure(actor, businessType?, uiComplexity?, onboardingState?, reason?)` — ubah hanya field yang diberikan.
- **Non-destruktif (FR-13)**: semua operasi hanya menyentuh kolom konfigurasi tenant; data operasional tidak pernah dihapus, dan mengaktifkan kembali modul memulihkan akses ke data yang sama.
- Otorisasi: izin administratif FR-12 `user.manage` (tenant-scoped, aktor nonaktif ditolak); setiap perubahan dicatat audit (`feature_profile.changed`, `module.enabled`, `module.disabled`, `tenant.configured`) — FR-12.
- Exception `TenantNotFound` (404). Audit enum `AuditAction` diperluas 4 aksi.

## Catatan

- **Keputusan**: konfigurasi profil fitur dipetakan ke izin `user.manage` karena FR-12 tidak menyediakan izin khusus settings; dapat dipersempit bila izin `settings.manage` ditambahkan kelak.
- UI pemilih profil/onboarding belum di-wire (pola irisan Fase 3: domain + test dulu).
- Modul mengatur visibilitas menu; penegakan otorisasi per-endpoint saat UI dibangun.

---

# Rebuild POS Universal — Fase 3: Audit tindakan sensitif (FR-12) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 3 §24, Sprint 3 "Audit tindakan sensitif", FR-12 §405–428 "Semua tindakan sensitif harus dicatat bersama aktor, waktu, perangkat, jenis tindakan, dan alasan jika diperlukan", model data `audit_logs` §14, use case infra "Logging dan audit" §10.4, skenario test §22)

## Perubahan

### Katalog aksi audit
- **`app/Enums/AuditAction.php`** — sumber tunggal nama aksi `audit_logs`: `sale.created`, `receivable.payment`, `user.created`, `user.updated`, `user.deactivated`, `user.reactivated`, `user.role_changed`, `backup.restored`, `report.financial.viewed`.
- `CreateSale` dan `RecordReceivablePayment` direfaktor memakai konstanta katalog (nilai string identik; perilaku tidak berubah).

### Perekam jejak audit terpusat (`app/Audit/AuditRecorder.php`)
- Use case infra §10.4: menulis baris append-only `audit_logs` dengan resolusi berlapis — tenant eksplisit (CLI/test deterministik) → tenant aktor → konteks request (`TenantContext`); `deviceId` eksplisit untuk perangkat (Fase 4).
- `record(action, ?actor, entityType, entityId, reason, metadata)` memakai model `AuditLog` lama (uuid PK, `UPDATED_AT = null` = append-only; tidak diubah).

### Wiring ke tindakan sensitif (FR-12: aktor + waktu + jenis + alasan + perangkat)
- **`ManageUser`** — setiap operasi BERHASIL dicatat: `user.created` (metadata peran), `user.updated`, `user.deactivated`/`user.reactivated` + parameter `?reason`, `user.role_changed` (metadata peran) + `?reason`. Entity = user, `entity_id` = **UUID sync identity** (stabil antar-perangkat). Operasi gagal (validasi/otorisasi/last-owner) **tidak** menulis jejak.
- **`BackupManager::restore`** — mencatat `backup.restored` (aktor opsional, metadata berkas + versi skema + snapshot keselamatan) **setelah** restore sukses, sehingga jejak berada di DB hasil restore sebagai penanda pemulihan. **Best-effort**: kegagalan tulis audit (mis. backup lama tanpa tabel `audit_logs`) hanya masuk log, tidak menggagalkan restore.
- **`SalesReport`** — akses laporan keuangan dicatat (`report.financial.viewed`, metadata periode) **hanya bila aktor nyata**; CLI/otomasi tanpa aktor tidak mencatat (hindari kebisingan). Pemeriksaan izin dipindah ke `range()` (basis) agar `daily()` dan pemanggilan langsung sama-sama tervalidasi.

## Catatan

- `device_id` masih `null` sampai identitas perangkat tersedia (Fase 4 device handshake); `AuditRecorder` sudah menerima `deviceId` eksplisit.
- UI daftar/filter audit belum di-wire (pola irisan Fase 3: domain + test dulu).
- Percobaan tindakan sensitif yang DITOLAK belum dicatat (hanya tindakan berhasil); dapat ditambahkan sebagai kebijakan keamanan berikutnya.

---

# Rebuild POS Universal — Fase 3: Peran pengguna (FR-12) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 3 §24, Sprint 3 "Peran pengguna", FR-12 §405–428, model data `roles`/`permissions` §14, "Otorisasi pada setiap use case" §10.2, skenario test §22)

## Perubahan

### Katalog peran & izin (FR-12) — sumber tunggal kebenaran
- **`app/Enums/Permission.php`** — enum 9 izin FR-12 (nilai = nama izin spatie, label bahasa Indonesia): `access.module`, `price.update`, `discount.apply`, `transaction.void`, `stock.adjust`, `report.financial.view`, `user.manage`, `payment_gateway.configure`, `backup.restore`.
- **`app/Enums/Role.php`** — enum 6 peran awal FR-12 (label Indonesia) + **matriks izin default** lewat `permissions()`:
  - `owner` → semua izin; `admin` → semua kecuali `backup.restore`; `supervisor` → modul, diskon, void, stok, laporan; `cashier` → modul, diskon, void; `inventory_manager` → modul, stok; `viewer` → modul, laporan.
  - Otorisasi selalu lewat **izin**, bukan nama peran (peran hanya kumpulan izin awal).
- **`database/seeders/DefaultRolesSeeder.php`** — seed **idempoten** (`findOrCreate` + `syncPermissions` dari matriks; `forgetCachedPermissions` di awal & akhir); additive terhadap peran legacy (`pemilik` dkk. tidak disentuh). Dipanggil dari `DatabaseSeeder` + command **`pos:roles:seed`**.

### Gerbang otorisasi operasi sensitif (`app/Support/Auth/`)
- **`ActionAuthorizer`** — `assert(User, Permission|string)`: aktor **nonaktif ditolak** untuk semua operasi sensitif; izin diperiksa via Gate `can()`; exception `ActionForbidden` (403, pesan berisi label + id izin). `assertIf(?User, ...)` untuk use case yang boleh berjalan tanpa aktor (CLI) namun tetap memeriksa izin bila aktor diberikan.
- **`AppServiceProvider`** — `Gate::before` menghubungkan katalog spatie permission ke `can()` app-wide; hanya **memberi** akses bila izin dimiliki, selain itu `null` (eval policy normal); `PermissionDoesNotExist` ditangkap agar izin legacy yang belum di-seed tidak melempar di view Blade.

### Use case pengelolaan pengguna (`app/Actions/Users/ManageUser.php`)
- `create(actor, input, ?Role)` — validasi (nama/email unik global/password aturan Fortify), `tenant_id` eksplisit, `is_active=true`, UUIDv7 sync identity (model `User` kini memakai `HasSyncIdentity`), role opsional; `updateProfile`, `setActive`, `assignRole` (sync, hapus role dengan `null`).
- **Aturan keamanan owner terakhir**: `LastOwnerProtected` (422) — owner aktif terakhir tidak dapat dinonaktifkan atau diturunkan perannya (mencegah toko kehilangan akses admin).
- Pencarian pengguna deterministik per-tenant (`withoutGlobalScope('tenant')` + `WHERE tenant_id`) → `UserNotFound` (404) bila di luar tenant.
- `UserFactory` kini mendefinisikan `is_active => true` (mencerminkan default skema; sebelumnya atribut in-memory `null`).

### Enforce FR-12 di titik sensitif yang sudah ada
- `BackupManager::restore(..., ?User $actor = null)` → izin `backup.restore` diperiksa **sebelum** validasi file bila aktor diberikan (CLI operator tetap tanpa aktor).
- `SalesReport(..., ?User $actor = null)` → izin `report.financial.view` di `daily()` (kompatibel ke belakang: tanpa aktor tetap jalan).

### Exception
- `app/Exceptions/Auth/ActionForbidden.php` (403), `app/Exceptions/Users/UserNotFound.php` (404), `app/Exceptions/Users/LastOwnerProtected.php` (422) — pola render JSON `['message']` konsisten dengan exception lain.

## Catatan

- **UI/API pengelolaan pengguna belum di-wire** (pola irisan Fase 3: domain + test dulu; UI menyusul).
- Aturan last-owner membutuhkan owner lain DULU: nonaktifkan/demote owner kedua dulu baru yang terakhir (dua-owner aman).
- Izin yang belum dikenal pada `ActionAuthorizer` diabaikan (tidak memblokir diam-diam); security tetap dipegang Gate bilamana izin itu nanti terdaftar.

---

# Rebuild POS Universal — Fase 3: Backup & pemulihan (FR-11) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 3 §24, Sprint 3 "Backup dan pemulihan", FR-11 §394–405, plan §21 Backup/restore/migrasi §1311–1342, use case BackupOverdue alert §10.2, skenario test §22)

## Perubahan

### Domain (`app/Backup/` — use case `CreateBackup` / `RestoreBackup`)
- **`BackupDriver`** (interface adapter, plan §11 "Adapter server database"):
  - `SqliteBackupDriver` — snapshot konsisten via **`VACUUM INTO`** (mekanisme resmi SQLite; tanpa binary `sqlite3`, jadi aman di perangkat pengguna/NativePHP); restore: validasi `PRAGMA integrity_check` sumber → salin ke file temporer → **rename atomik** → bersihkan sidecar `-wal`/`-shm` basi → verifikasi lagi; tolak `:memory:` (sasaran restore = database file / "database baru", §22).
  - `MysqlBackupDriver` — dump via **`spatie/db-dumper`** (kelas `MariaDb`, `--single-transaction` = snapshot konsisten §21 + `--routines`; kredensial via `--defaults-extra-file`, tidak tampil di daftar proses); restore via klien `mysql` dengan **`MYSQL_PWD`** (bukan `-p`); isHealthy via `SELECT 1`.
  - `BackupDriverFactory` — pilih driver dari `database.default` (sqlite | mysql/mariadb; selain itu → `UnsupportedBackupDriver`).
- **`BackupInfo`** (readonly VO): filename, path, size, checksum `sha256:`, schema_version, app_version, driver, encrypted, created_at; `fromFile`/`fromArray`/`checksumMatches`.
- **`BackupManager`** (use case FR-11):
  - `create(?directory, ?encrypt)` — snapshot ke file temporer dulu (create yang gagal tidak pernah masuk manifest), enkripsi opsional AES-256-CBC via **APP_KEY** (`Crypt`), sidecar `*.meta.json` (checksum + versi skema) ditulis atomik, **`manifest.json`** mencatat `last_backup_at` + daftar backup (FR-11 "waktu backup terakhir"); `--path=` mendukung media eksternal dan tetap terdaftar di manifest.
  - `restore(backup, force)` — alur wajib: cek file → **validasi integritas (checksum)** → **validasi versi skema** (backup berskema lebih baru ditolak) → **konfirmasi eksplisit `--force`** → **snapshot keselamatan database aktif dibuat otomatis** sebelum menimpa (§21) → restore → pemeriksaan kesehatan pasca-restore; metadata hilang → ditolak (ekspor data BUKAN pengganti backup yang konsisten, per FR-11); backup terenkripsi didekripsi ke file temporer lalu diverifikasi ulang saat restore.
  - `latest()` / `all()` / `manifestPath()` — untuk observability §23 dan alert `backup.overdue`.
- **Exception `app/Exceptions/Backup/`** (pola render `ReceivableClosed`): `BackupException` (basis, 422), `BackupNotFound` (404), `BackupCorrupted` (422), `BackupSchemaMismatch` (422), `BackupRequiresConfirmation` (422), `BackupFailed` (500), `UnsupportedBackupDriver` (500).

### CLI
- `pos:backup:create {--path=} {--encrypt}` — backup manual (FR-11 MVP: manual), output path + ukuran + driver + skema + enkripsi.
- `pos:backup:restore {backup} {--force}` — restore dengan validasi & konfirmasi; gagal tanpa `--force`, menampilkan path snapshot keselamatan.
- `pos:backup:status` — waktu backup terakhir + tabel daftar backup (FR-11 "Sistem menampilkan waktu backup terakhir").
- `BackupManager` dibind sebagai singleton di `AppServiceProvider` (komando resolve dari container → mudah diganti di test).

### Konfigurasi (`config/pos.php`)
- `pos.version` (default `0.4.0`), `pos.schema_version` (default `0.4.0`, §21 "Setiap rilis memiliki versi skema database").
- `pos.backup.path` (default `storage_path('app/backups')`), `pos.backup.encrypt` (default `false`, opsi FR-11), `pos.backup.max_age_days` (default 7).

### Alert (FR-10 + FR-11)
- `Alert::TYPE_BACKUP_OVERDUE` (`'backup.overdue'`) + `AlertGenerator::backupOverdue()` — alert bila backup terakhir lebih tua dari `pos.backup.max_age_days`; belum pernah backup tidak alert (anti-noise); dedup FR-10 (unread) dihormati; masuk ke `run()` + deskripsi `pos:alerts:generate` + komentar scheduler.

### Test (+33)
- `SqliteBackupDriverTest` (6): snapshot konsisten (isi + integrity_check), restore atomik menimpa target, menolak file korup, menolak `:memory:` (create & restore), isHealthy.
- `MysqlBackupDriverTest` (4): nama driver; perintah dump memuat `--single-transaction` + `--routines` + credentials file tanpa password; argumen klien `mysql` tanpa password; health check.
- `BackupManagerTest` (12): create → dump+sidecar+manifest; direktori eksternal terdaftar di manifest; restore wajib konfirmasi; file di-tamper → `BackupCorrupted`; skema masa depan → `BackupSchemaMismatch`; metadata hilang → ditolak; restore ke "database baru" + snapshot keselamatan otomatis berisi kondisi lama; file hilang → `BackupNotFound`; urutan `latest()`/`all()` terbaru-dulu; direktori kosong; enkripsi round-trip (plaintext tidak bocor, restore berhasil); kunci salah → ditolak.
- `BackupCommandsTest` (5): `pos:backup:create` (default & `--path` eksternal), `pos:backup:status`, restore tanpa `--force` gagal dengan pesan konfirmasi, restore `--force` memulihkan database yang sengaja dirusak.
- `BackupOverdueTest` (6): konstanta tipe; alert dibuat saat backup basi (payload `last_backup_at`); dedup unread lalu respawn setelah dibaca; backup fresh → 0; belum pernah backup → 0; ter-wire di `run()`.

## Catatan
- **Keputusan dependency (hasil riset, permintaan user):** untuk SQLite/native, paket `spatie/db-dumper` justru kurang cocok (butuh binary `sqlite3` di mesin pengguna + dump teks-SQL); snapshot resmi SQLite (`VACUUM INTO`) tersedia langsung di PHP → **driver SQLite tanpa dependency**. Untuk MySQL/MariaDB (web), `spatie/db-dumper` dipertahankan (maintained v4.1.1 Mei 2026, kelas `MariaDb`, PHP 8.4 di DDEV). `spatie/laravel-backup` tetap TIDAK dipakai (tidak ada restore + enkripsi di luar kotak; 80% domain tetap harus dibangun sendiri).
- Laravel 13 tidak punya `db:snapshot`/`DatabaseSnapshots` — driver sendiri + `VACUUM INTO`/`mariadb-dump` adalah penggantinya (sudah tertulis di atas).
- Tanpa migration — FR-11 tidak mengubah skema (hanya domain + konfigurasi).
- **Catatan gotcha test:** `TestCase::seed()` (public, milik Laravel) bentrok dengan helper `seed()` — helper diberi nama `seedItems()`; `Encrypter` Laravel 13 menerima key **raw bytes** (`generateKey`), bukan `base64:` string (dekode dilakukan provider, bukan konstruktor); direktori induk file sqlite test harus dibuat dulu (`storage/app/testing`).
- Tidak ada regresi: suite penuh **168 passed / 0 failed / 6 skipped** (+33 dari 135); Pint bersih.

---

# Rebuild POS Universal — Fase 3: Laporan harian (FR-09) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 3 §24, Sprint 3 "Laporan harian", FR-09, use case `GenerateReport` §10.2, `GET /api/v1/reports/sales`)

## Perubahan

### Domain (tanpa migration — laporan hanya baca data yang sudah ada)
- **`SalesReport`** (use case `GenerateReport`):
  - `daily(?date)` / `range(from, to)` — metrik per periode (rentang diurutkan otomatis, inklusif, default hari ini):
    - `omzet_minor` (Σ total_minor, termasuk kredit), `penerimaan_minor` (Σ paid_minor kasir), `piutang_baru_minor` (Σ bagian belum dibayar transaksi kredit) — **tiga angka berbeda**, sesuai aturan FR-09 "Omzet, penerimaan kas, piutang, dan laba tidak boleh diperlakukan sebagai angka yang sama";
    - `transaksi_count`, `transaksi_kredit_count`, `transaksi_berdiskon_count`, `rata_rata_transaksi_minor`, `diskon_minor` (Σ diskon transaksi + Σ diskon item), `metode_pembayaran` (count + omzet + penerimaan per metode);
    - `produk_terlaris` (kuantitas desc, dengan omzet per produk), `stok_menipis` (query kondisi, bukan pembuatan alert), `riwayat` (500 transaksi terbaru, urut terbaru dulu);
  - `metricDefinitions()` — definisi metrik yang wajib disebut laporan (FR-09);
  - transaksi yang dihapus (SoftDeletes) tidak dihitung; tenant deterministik bila `tenantId` eksplisit.
- **`CsvExporter`** — ekspor CSV (FR-09 "Ekspor CSV"): UTF-8 BOM (Excel), delimiter default `;` (konvensi ID), sel berisi delimiter/kutip/baris-baru di-quote dengan kutip didobel, kunci hilang/null → kosong.

### Test
- `SalesReportTest` (10): pemisahan omzet vs penerimaan vs piutang (penagihan piutang via `RecordReceivablePayment` TIDAK mengubah metrik kasir), rentang tanggal, diskon transaksi+item, ringkasan metode bayar, produk terlaris (urutan + omzet per produk), stok menipis (hanya di bawah minimum, unlimited diabaikan), riwayat terbaru-dulu, hari kosong → nol semua, definisi metrik, batas tenant.
- `CsvExporterTest` (3): header+baris+BOM, quoting (delimiter, kutip, baris baru), kunci hilang/null.

## Catatan
- Penagihan piutang (receivable_payments) sengaja TIDAK tercampur ke `penerimaan_minor` (dokumentasi di definisi metrik); laporan piutang/umur piutang adalah laporan lanjutan.
- UI laporan terpisah dari halaman `reports` legacy (masih memakai kolom `total_amount`); integrasi UI baru ke `SalesReport` + ekspor menyusul di irisan UI.
- Tidak ada regresi: suite penuh **135 passed / 0 failed / 6 skipped**; Pint bersih (4 files).
- Gotcha terkonfirmasi: `created_at` tidak dapat di-override lewat `Model::create` (tidak ada di `$fillable` → dibuang lalu di-set fresh); helper test memakai `forceCreate`.

---

# Rebuild POS Universal — Fase 3: Impor produk (FR-02) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 3 §24, Sprint 3 "Impor CSV dengan validasi", FR-02, use case `ImportProducts` §10.2)

## Perubahan

### Domain (tanpa migration — impor tidak mengubah skema)
- **`ProductCsvParser`** — parser CSV produk:
  - Header memakai alias Indonesia/Inggris (mis. `nama`/`name`, `harga_jual`/`price`, `stok`/`stock`, `aktif`/`is_active`);
  - delimiter dideteksi otomatis (`;`, `,`, tab); baris kosong & komentar `#` dilewati (template berisi contoh baris berkomentar);
  - sel dengan tanda kutip (koma di dalam nilai) didukung; baris dengan jumlah kolom tidak sama dilaporkan sebagai kesalahan tingkat berkas (dengan nomor baris sumber) dan dikeluarkan — dasar "mekanisme koreksi" FR-02;
  - kolom tidak dikenal & header duplikat dilaporkan, parsing tetap lanjut;
  - `template()` menyediakan berkas contoh untuk diunduh UI.
- **`ProductImportResult`** — laporan terstruktur per baris: `line` sumber, status `failed|valid|created`, daftar kesalahan per kolom, dan `product_id` untuk baris tercipta; ringkasan `total/valid/failed/created`.
- **`ImportProducts`** (use case):
  - `preview()` — validasi semua baris + laporan kesalahan TANPA menulis (pratinjau FR-02); `commit()` — baris valid disimpan dalam satu transaksi atomik, baris gagal dilaporkan untuk dikoreksi lalu diimpor ulang;
  - validasi: nama wajib (≤200), **SKU wajib** (kolom NOT NULL legacy; unik dalam berkas + DB), harga jual wajib > 0 (angka), harga beli ≥ 0, stok & stok minimum bilangan bulat ≥ 0, barcode (nullable; unik dalam berkas + DB;
  - pengecekan unik SKU/barcode TANPA auto-scope tenant karena constraint legacy unik global — sesuai kenyataan skema;
  - kategori find-or-create per tenant (case-insensitive); `category_id` NOT NULL → baris tanpa kategori otomatis diberi kategori `Tanpa Kategori`; `buy_price` kosong → 0.00 (default kolom), `is_active` default aktif (`1/true/ya/aktif`), slug dihasilkan dari nama dengan akhiran unik.

### Test
- `ProductCsvParserTest` (9): header Inggris + kanonik, alias Indonesia + delimiter `;`, sel berkutip berisi koma, baris kosong/komentar dilewati (nomor baris akurat), jumlah kolom salah dilaporkan + dikecualikan, header wajib hilang, berkas kosong, header tidak dikenal/duplikat, template.
- `ImportProductsTest` (12): preview tanpa menulis; preview mendeteksi SKU duplikat dari DB; commit membuat produk lengkap (sku/barcode/slug/price/buy_price/stock/min_stock/is_active/tenant/uuid/version); baris invalid dilewati + laporan per kolom; duplikat dalam berkas (SKU & barcode); barcode duplikat terhadap DB; kategori dibuat sekali & dipakai ulang case-insensitive; default field opsional (+`Tanpa Kategori`, buy_price 0, tidak aktif); SKU wajib; varian harga invalid; harga beli negatif; pipeline penuh parse→commit.

## Catatan
- Stok hasil impor ditulis langsung ke kolom `stock` sebagai stok awal (konsisten dengan CRUD produk yang ada; integrasi buku besar stok untuk pembukaan stok menyusul).
- Satuan dasar produk tidak diimpor pada irisan ini (FR-02 "Satuan" menyusul bersama irisan satuan).
- `categories.name` unik global (warisan) → fallback memakai kategori yang sudah ada bila bentrok lintas-tenant (transisi; unik-per-tenant menyusul di migration depan).
- Tidak ada regresi: suite penuh **122 passed / 0 failed / 6 skipped**; Pint bersih (5 files).

---

# Rebuild POS Universal — Fase 3: Alert lokal + stok minimum (FR-10) (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 3 §24, FR-10 Alert, §17.2 `alerts`)

## Perubahan

### Skema (migration baru)
- `2026_10_10_000014` (DDL): tabel `alerts` — uuid (sync identity), tenant_id, device_id nullable ("jika lokal"), `alert_type`, `deduplication_key`, `severity` (info|warning|critical), `payload` JSON, `read_at`, `version`, timestamps; indeks komposit `(tenant_id, deduplication_key)`.
- Deduplikasi sengaja TIDAK memakai unique constraint: dedup diaplikasikan terhadap alert **belum dibaca** dengan key yang sama, sehingga kondisi yang bertahan tidak memicu alert berulang, tetapi kondisi yang muncul kembali setelah alert dibaca tetap bisa dibunyikan (respawn).

### Domain
- **`Alert`** model (BelongsToTenant, HasSyncIdentity): konstanta tipe (`stock.low`, `product.incomplete_data`, `receivable.due`) + severity; scope `unread()`; helper `markAsRead()`.
- **`AlertGenerator`** (layanan): tiga kategori kondisi-lokal (FR-10 butir 1–2; butir piutang jatuh tempo juga termasuk):
  1. `stock.low` — produk non-unlimited dengan `min_stock > 0` dan `stock <= min_stock` (warning);
  2. `product.incomplete_data` — produk tanpa harga jual (> 0) atau tanpa barcode (warning);
  3. `receivable.due` — piutang open dengan `due_at` lewat (critical).
  - Payload berisi `action` (aksi yang disarankan) + `target` (navigasi) sesuai FR-10.
  - Deterministik bila `tenantId` eksplisit (auto-scope tenant dimatikan); tanpa `tenantId` mengikuti konteks request.
- **`pos:alerts:generate`** (command): iterasi semua tenant, memanggil generator; didaftarkan di scheduler `routes/console.php` — `hourly()->withoutOverlapping()` (perlu scheduler berjalan, mis. cron `php artisan schedule:run`).

### Test
- `tests/Feature/Alerts/AlertGeneratorTest.php` (12 test): pembuatan alert stok menipis (payload + dedup key + severity), dedup berulang tidak mengganda saat belum dibaca, respawn setelah dibaca, tanpa alert saat stok cukup, abaikan unlimited/`min_stock=0`, produk tanpa harga/barcode, tanpa alert saat data lengkap, piutang lewat jatuh tempo (critical), belum jatuh tempo / closed / tanpa due_at → tanpa alert, batas tenant, dan command console untuk semua tenant.

## Catatan
- UI alert (bell + daftar + aksi mark-as-read) menyusul di irisan UI Fase 3/4; irisan ini menyiapkan skema + mesin + test (pola domain-first).
- Alert piutang jatuh tempo memakai `due_at`; UI piutang (pengaturan tenggat) masih irisan terpisah — payload `target` sementara `/customers`.
- Tidak ada regresi: suite penuh **101 passed / 0 failed / 6 skipped**; Pint bersih (6 files).

---

# Rebuild POS Universal — Fase 3 (awal): Piutang dasar (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 3 §24, FR-07 Piutang, §17.2 `receivables`/`receivable_payments`, §17.3 step 6)

## Perubahan

### Skema (migration baru)
- `2026_10_10_000012` (DDL): tabel `receivables` + `receivable_payments`.
  - `receivables`: uuid (sync identity), tenant_id, customer_id, transaction_id (plan `sale_id` → tabel transaksi selama rebuild), `original_amount_minor`, `remaining_minor` (cache atomik "sisa saldo" — hanya boleh diubah domain actions), `due_at` nullable, `status` open|closed, version.
  - `receivable_payments`: uuid, tenant_id, receivable_id, `payment_id` nullable (dicadangkan untuk tabel `payments` Fase 5, tanpa FK), amount_minor, payment_method, recorded_by, `client_request_id` unik (idempotensi), notes, paid_at, version.
- `2026_10_10_000013` (DML backfill, terpisah per §16): buat receivable untuk semua transaksi `payment_status='credit'` lama tanpa receivable; lewati baris terisi (idempoten).

### Domain actions
- `CreateSale` dilengkapi **§17.3 langkah 6**: penjualan kredit kini membentuk `Receivable` (original = remaining = saldo belum dibayar, status open) dalam satu transaksi atomik; `customers.balance` tetap mirror transisi (hanya disentuh domain actions).
- **`RecordReceivablePayment`** (baru): entry point pembayaran piutang dengan jaminan yang sama seperti checkout — atomik, **idempoten** (`client_request_id`), row-lock receivable, jumlah harus positif dan ≤ sisa, receivable tertutup tidak bisa dibayar lagi, `remaining_minor` + status ditutup saat lunas, mirror `customers.balance` dikurangi (tidak pernah negatif), audit `receivable.payment`.
- Exception baru `ReceivableClosed` (422 JSON); overpay/amount invalid memakai `InvalidPaymentAmount`.

### Test
- `tests/Feature/Receivables/ReceivableTest.php` (9 test): pembentukan piutang dari saldo belum dibayar, lunas penuh tanpa piutang, pembayaran sebagian (sisa berkurang, tetap open), pelunasan (closed), overpay ditolak + rollback, bayar receivable tertutup ditolak, amount 0/negatif ditolak, idempotensi replay, audit log.
- `CreateSaleTest.test_partial_payment_...` diperluas: assert receivable terbentuk (original/remaining 5000, open).

## Catatan
- UI piutang (daftar jatuh tempo + form pembayaran) menyusul di irisan Fase 3 berikutnya; irisan ini domain + skema + test (pola sama seperti Fase 2).
- `customers.balance` (decimal) masih mirror transisi; increment berikutnya bisa menurunkan dari penjumlahan receivable.
- Tidak ada regresi: suite penuh **89 passed / 0 failed / 6 skipped**; Pint bersih (11 files).

---

# Rebuild POS Universal — Fase 2 (lanjutan): Checkout UI → Domain Action (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 2 §24, FR-04 kasir, FR-06 diskon, FR-07 piutang dasar, FR-08 stok, §17.3)

## Perubahan

### Domain action (perluasan `CreateSale`)
- **Diskon (FR-06):** diskon nominal per item (`discount_minor`), diskon transaksi nominal (`discount_minor`) atau persen (`discount_percent`, dibulatkan ke minor unit; aturan gabungan: nominal menang atas persen). Semua di-snapshot di line item — perubahan harga/diskon nanti tidak mengubah transaksi historis.
- **Konversi satuan (FR-08):** `conversion_factor` per item; validasi stok & mutasi dalam satuan dasar (base stock unit), mis. 2 dus × 3 pcs = 6 unit keluar.
- **Kredit/sebagian (FR-07 dasar):** `paid < total` → penjualan kredit, wajib pilih pelanggan; **hanya bagian belum dibayar** yang masuk `customers.balance` (piutang tidak boleh terbentuk tanpa saldo belum dibayar). `paid > total` hanya untuk tunai (kembalian); non-tunai overpay ditolak.
- `payment_status`: `paid` / `credit` dihitung Action.

### Skema (migration baru)
- `2026_10_10_000010` (DDL): `transactions.subtotal_minor` + `discount_minor`, `transaction_items.discount_minor`.
- `2026_10_10_000011` (DML backfill, terpisah per §16): `subtotal_minor = total_minor` untuk riwayat lama (belum pernah ada diskon), lewati baris terisi.

### UI checkout (wiring ke domain)
- `resources/views/pages/transactions/create.blade.php` ditulis ulang: `confirmSave`/`save` kini memanggil **`CreateSale`** — checkout atomik + idempoten dari UI, sesuai FR-04.
- **Idempotensi:** `client_request_id` (UUIDv7) dibuat saat Review & Confirm, dipertahankan sampai sukses (replay aman; tombol ganda dicegah).
- **Kegagalan tidak mengosongkan keranjang** (FR-04): stok kurang/pembayaran invalid → modal ditutup, keranjang tetap, toast error.
- **Diskon di UI:** pilihan None / Percent / Nominal; tampilan Subtotal, Diskon, Total, Kembalian dalam minor unit (IDR exponent 0).
- **Kredit di UI:** sinyal "Credit sale — select a customer" + peringatan amber di modal konfirmasi.
- Guard double-submit (`wire:loading.attr="disabled"` + status "Saving...").

### Test
- `tests/Feature/Sales/CreateSaleTest.php` diperluas 8 → 13 test: diskon nominal transaksi, diskon persen (pembulatan), snapshot diskon per item, faktor konversi (mutasi stok basis), kredit sebagian (saldo += belum dibayar, status credit), lunas penuh tidak menyentuh saldo, non-tunai overpay ditolak.
- `TransactionsTest` (4 test) **kini lulus** — bug lama `preg_match(): regular expression is too large` (kompilasi Blade/Livewire) ter-fix oleh rewrite halaman checkout. Akar masalahnya di vendor: precompiler Flux membungkus `<flux:input>` menjadi direktif `@component(...)` sepanjang ~39KB berisi direktif bersarang, lalu precompiler Livewire SMA salah mencocokkannya dan membangun pola regex >45KB.

## Catatan
- `payments`/`receivables` (tabel terpisah) belum dibuat — Fase 2 mencatat pembayaran & sisa di kolom transaksi; tabel piutang penuh menyusul di Fase 3 (Piutang dasar) / Fase 5 (Payment).
- UI keranjang masih float decimal (transisi); Action tetap menerima integer minor units saja.
- Tidak ada regresi: suite penuh **80 passed / 0 failed / 6 skipped**; Pint bersih untuk semua file baru/ubah.

---

# Rebuild POS Universal — Fase 2: Checkout Atomik & Idempoten (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 2 §17.1/§17.2/§21/§24, FR-08 ledger, FR-12 audit)

## Perubahan

### Domain action
- `app/Actions/Sales/CreateSale.php` — satu-satunya pintu masuk domain untuk transaksi penjualan. Jaminan yang di-enforce di sini:
  - **Atomik:** order, item, stok, ledger, dan audit commit/rollback bersama (`DB::transaction`).
  - **Idempoten:** replay dengan `client_request_id` yang sama mengembalikan penjualan asli; race kondisi aman lewat unique constraint.
  - **Stok aman:** validasi atas baris yang di-`lockForUpdate()`, tidak pernah negatif; mendukung `is_unlimited_stock`.
  - **Ledger (FR-08):** setiap barang keluar tercatat `StockMovement` dengan `reference_type='sale'` + `reference_id`; observer stok tidak dipakai di sini (hindari duplikasi, C5).
  - **Uang jujur (FR-04/§17.1):** semua jumlah dalam integer minor unit, harga di-snapshot di line item.
  - **Audit (FR-12):** `audit_logs` dengan action `sale.created` (entity_id = uuid transaksi).

### Value object & exception
- `app/Support/Money/Money.php` — uang immutable dalam minor unit (`fromMajor/minor/major/add/subtract/multiply/zero`), exponent dari `config/pos.php`.
- `app/Exceptions/Sales/InsufficientStock.php`, `app/Exceptions/Sales/InvalidPaymentAmount.php` — exception domain, `render()` → 422 JSON.

### Skema (migration baru)
- `2026_10_10_000008` (DDL): `transactions.client_request_id` (string 64, unique, nullable), `total_minor`/`paid_minor`/`change_minor`, `transaction_items.unit_price_minor`/`subtotal_minor`.
- `2026_10_10_000009` (DML backfill, terpisah sesuai §16): isi kolom minor dari kolom decimal lama via `chunkById` (IDR exponent 0), lewati baris yang sudah terisi.

### Model
- `Transaction` / `TransactionItem` — fillable + cast integer untuk kolom minor; kolom decimal lama tetap dipertahankan sebagai mirror transisi untuk UI lama (dihapus pada increment terpisah nanti).

### Test
- `tests/Feature/Sales/CreateSaleTest.php` (8 test, `LazilyRefreshDatabase`): create atomik + ledger + audit, idempotensi replay, stok kurang → rollback penuh, pembayaran cash kurang ditolak, overpay cash → kembalian, non-tunai harus sama persis, harga di-snapshot, saldo customer bertambah (debit transisi).

## Catatan
- Wiring halaman Volt `transactions/create` lama ke Action ini = increment terpisah (transisi: kolom decimal lama tetap ditulis sebagai mirror agar UI lama tetap jalan).
- Saldo `customers.balance` masih decimal (increment terpisah untuk konversi ke minor unit).
- Bug lama `TransactionsTest > cannot create transaction without items` (kompilasi Blade Livewire) masih ada, tidak terkait increment ini.

---

# Rebuild POS Universal — Fase 1: Fondasi Bisnis (belum dirilis)

**Tanggal:** 10 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 1 — Fondasi bisnis)

## Keputusan arsitektur yang dikunci

- **Idempotensi & ID:** UUIDv7 sebagai primary key untuk tabel fondasi (`HasUuids` → `Str::uuid7()`).
- **Tenancy:** satu usaha per instalasi, skema tetap siap multi-tenant (`tenant_id` di setiap record).
- **Offline:** hanya untuk build native (NativePHP + SQLite); Web/PWA online-first pada MVP.

## Perubahan

### Konfigurasi
- `config/pos.php` — konfigurasi domain (currency, minor unit, tenancy, offline, sync). `env()` hanya di config.

### Tenancy & identitas sinkronisasi
- `app/Support/Tenancy/TenantContext.php` — konteks tenant per-request (singleton).
- `app/Models/Concerns/BelongsToTenant.php` — trait: global scope tenant + auto-assign `tenant_id` saat create (aktif hanya jika tenant ter-bind, aman untuk queue/CLI).
- `app/Models/Concerns/HasSyncIdentity.php` — trait: auto-assign `uuid` (UUIDv7) untuk record dengan PK bigint lokal.
- Retrofit tabel inti (migration `2026_10_10_000006` + backfill `2026_10_10_000007`): `users`, `categories`, `products`, `customers`, `transactions`, `transaction_items`, `stock_movements` mendapat `uuid`, `tenant_id`, `version`; entitas historis (`categories`, `products`, `customers`, `transactions`) mendapat soft delete; `transactions` mendapat `location_id`/`device_id`; `users` mendapat `is_active`.
- Model inti menerapkan trait di atas + `SoftDeletes`; default `version = 1` di-mirror di `$attributes` (§16).

### Enum
- `app/Enums/FeatureProfile.php` — profil fitur (Simple, Retail, F&B, Credit Sales, Advanced) beserta daftar modul (FR-13).

### Skema (migration baru)
- `tenants`, `locations`, `devices`, `feature_flags`, `audit_logs` — UUID PK, foreign key, index sesuai TDD §17.2.

### Model
- `Tenant`, `Location`, `Device`, `FeatureFlag`, `AuditLog` (+ factory untuk kebutuhan test).

### Konfigurasi runtime
- `AppServiceProvider` — `Model::preventLazyLoading()` aktif di environment `local` (best practices §1).

### Seeder
- `TenantSeeder` (tenant + lokasi default), didaftarkan di `DatabaseSeeder`.

### Test
- `tests/Feature/Foundation/TenantFoundationTest.php` (6 test, `LazilyRefreshDatabase`).
- `tests/Feature/Foundation/TenancyScopeTest.php` (5 test): uuid otomatis, scoping tenant, soft delete.
- Test lama `ProductsTest`/`CategoriesTest` diperbarui ke `assertSoftDeleted` (konsekuensi soft delete entitas historis).

## Catatan
- Bug lama yang belum diperbaiki: `TransactionsTest > cannot create transaction without items` gagal karena kompilasi Blade Livewire (regex terlalu besar).
- Migrasi lama `2026_07_06_010650_...` masih berupa no-op dan belum dirapikan (Pint).

---

# Rilis v1.0.0 — POS-DW

**Tanggal Rilis:** 3 Juli 2026

Sistem Manajemen Penjualan (POS) untuk cafe/coffee shop berbasis web.  
Dibangun dengan Laravel 13, Livewire 4, Flux UI, dan Volt.

---

## Fitur Utama

### Dashboard
- Ringkasan pendapatan harian
- Jumlah transaksi hari ini
- Peringatan stok menipis
- Grafik tren penjualan 7 hari terakhir
- Daftar transaksi terbaru
- Produk terlaris

### Manajemen Produk
- CRUD produk dengan gambar, harga, stok, dan kategori
- Dukungan stok tak terbatas (unlimited stock) untuk produk non-fisik
- Filter produk berdasarkan kategori
- Status stok: Normal, Stok Menipis, Stok Habis

### Kategori Produk
- CRUD kategori untuk pengelompokan produk
- Kategori default: Kopi & Espresso, Non-Coffee, Makanan Ringan, Makanan Berat, Minuman Segar

### Transaksi
- CRUD transaksi penjualan
- Pencarian dan pemilihan produk secara interaktif
- Banyak metode pembayaran: Tunai, Transfer, Kartu Debit, Kartu Kredit
- Perhitungan total, jumlah dibayar, dan kembalian otomatis
- Cetak struk (receipt) transaksi
- Nomor invoice otomatis

### Laporan
- Filter berdasarkan rentang tanggal (From — To)
- Total pendapatan, rata-rata nilai pesanan, item terjual
- Grafik pendapatan harian
- Distribusi metode pembayaran
- Penjualan per kategori
- Produk teratas berdasarkan pendapatan

### Manajemen Pengguna
- CRUD pengguna dengan role/permissions
- Autentikasi email + password
- Verifikasi email
- Reset kata sandi
- Dua peran default: admin (full akses) dan pemilik (produk, kategori, transaksi, laporan)

### Keamanan & Akses
- Role-Based Access Control (RBAC) dengan Spatie Permission
- CRUD roles dan permissions
- Autentikasi Dua Faktor (2FA/TOTP)
- Kode pemulihan (recovery codes) untuk 2FA

### Pengaturan Toko
- Nama toko, alamat, telepon, email
- Footer struk untuk cetak receipt
- Pengaturan profil pengguna
- Pengaturan tampilan (Light/Dark/System mode)
- Hapus akun

### Bahasa
- Seluruh antarmuka dalam Bahasa Indonesia
- Format nominal Rupiah (Rp)
- Zona waktu Asia/Jakarta

---

## Teknologi

| Komponen | Versi |
|----------|-------|
| PHP | ^8.3 |
| Laravel | ^13.7 |
| Livewire | ^4.1 |
| Flux UI | ^2.13.1 |
| Volt | ^1.10 |
| Laravel Folio | ^1.1 |
| Laravel Fortify | ^1.34 |
| Spatie Permission | ^7.4 |
| Database | MariaDB |

---

## Data Demo (Seeder)

**Akun:**
| Peran | Email | Password |
|-------|-------|----------|
| Admin | admin@testing.com | password |
| Pemilik | pemilik@testing.com | password |

**Data:**
- 5 kategori produk
- 34 produk contoh
- 30 transaksi contoh (30 hari terakhir)
- Pengaturan toko default

---

## Cara Install

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

---

## Catatan Rilis

- First release
- Semua fitur inti POS telah selesai
- Menggunakan bahasa Indonesia penuh
- Siap untuk production deployment
