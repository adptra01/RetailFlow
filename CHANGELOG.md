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
