# Rebuild POS Universal — Fase 5: Satu Sumber Kebenaran Uang Penjualan — Cermin Desimal Legacy Dihapus (Increment 57) (belum dirilis)

**Tanggal:** 15 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§17.1 nilai uang integer minor unit; §17.4 void & koreksi; §12 isolasi tenant; FR-09 laporan penjualan/margin). Perintah: `php artisan migrate` / `db:seed`.

## Latar
Sejak minor unit diperkenalkan (`add_idempotency_and_minor_units_to_sales_tables`), tabel penjualan menulis **dua** kolom untuk uang: `*_minor` (nilai kebenaran) **dan** kolom desimal lama (`transactions.total_amount/paid_amount/change_amount`, `transaction_items.unit_price/subtotal`, `sales_returns.total`, `sales_return_items.price/subtotal`) sebagai "cermin transisional" bagi UI pra-rebuild. Ini melanggar §17.1: ada dua sumber kebenaran yang bisa menyimpang, dan setiap penulis harus ingat menulis dua tempat.

Pembacanya tersebar: dashboard, daftar/struk transaksi, laporan penjualan (`/reports`), dan **laporan lanjutan** (margin, purchase-vs-sales) yang menjumlah kolom desimal lewat SQL mentah. Selama cermin ada, menghapusnya akan memutus tampilan.

## Keputusan desain
- **Alias baca, bukan kolom.** Nama lama (`total_amount`, `paid_amount`, `change_amount`, `unit_price`, `subtotal`, `price`, `total`) dipertahankan sebagai **accessor turunan** `*_minor` lewat `Money` — jadi tampilan Blade pra-rebuild tidak perlu diubah, tetapi tidak ada lagi penulis kedua. Accessor tidak berlaku di tingkat query builder, maka agregat SQL dipindahkan ke `*_minor` secara eksplisit.
- **Konversi lewat `Money`, tidak ada `/100` tetap** — ekspresi tidak bergantung `minor_exponent` (§17.1). Saat ini exponent 0 (IDR), sehingga nilai tampilan tidak berubah (peralihan aman nilai), tetapi ekspresi tetap benar bila exponent berubah.
- **Agregat minor lalu konversi di PHP.** `SUM(total_minor)`/`SUM(subtotal_minor)` di dashboard, `/reports`, dan aksi laporan lanjutan; nilai uang master produk (`buy_price`) masih desimal major sehingga konversi dilakukan di titik tampilan agar satuan konsisten.
- **`down()` hanya memulihkan skema, bukan data.** Kolom desimal dikembalikan (berisi 0); nilai kebenaran tetap pada `*_minor`.

## Perubahan
- **Migrasi baru** `2026_10_13_240000_drop_legacy_money_columns_for_sales` — menghapus kolom desimal legacy penjualan di semua driver via `Schema::hasColumn()` (tidak berefek bila sudah hilang).
- **`app/Models/Transaction.php`, `TransactionItem.php`, `SalesReturn.php`, `SalesReturnItem.php`** — hapus kolom legacy dari `$fillable`/`casts`; tambah accessor `totalAmount/paidAmount/changeAmount`, `unitPrice/subtotal`, `total`, `price/subtotal`.
- **`app/Actions/Sales/CreateSale.php`, `CreateSalesReturn.php`** — **hentikan dual-write**: hanya kolom `*_minor` yang ditulis.
- **Views** — `dashboard.blade.php`, `pages/reports/index.blade.php`, `pages/transactions/index.blade.php` (sort), `pages/transactions/[Transaction].blade.php` (halaman edit legacy: kini menulis `*_minor`, memperbaiki `customer` → `customer_name`, dan mengisi `tenant_id` eksplisit pada `insert()` item yang sebelumnya NULL).
- **`app/Actions/Reports/GenerateMarginHistoryReport.php`, `GeneratePurchaseVsSalesReport.php`** — agregat `subtotal_minor` + konversi `Money`.
- **Factories** — `TransactionFactory`, `TransactionItemFactory`, `SalesReturnFactory`, `SalesReturnItemFactory` menulis integer minor.
- **`database/seeders/ReturnSeeder.php`** — hapus cermin desimal.
- **Tes** — `DemoSeederTest` (kontrak: kolom legacy sudah tidak ada), `ReportsTest`, `SalesReportTest`, `AdvancedReportsTest`, `AdvancedReportsPagesTest`, `PaymentMethodDisplayTest`, `SaleApiTest` dialihkan ke kolom minor.

## Pengujian
- Suite **914 passed / 0 failed / 4 skipped (3718 assertions)** — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 491 file, tanpa perbaikan.
- Diverifikasi juga di **MySQL produksi** (database terpisah, lalu dihapus): `migrate:fresh` + `db:seed` tanpa error; query `information_schema` membuktikan seluruh kolom legacy penjualan **hilang**; 0 baris uang minor nol, 0 baris `tenant_id` NULL.

## Status suite
- **914 passed / 0 failed / 4 skipped** (3718 assertions).
- Sisa pekerjaan Fase 5: menyatukan dua jalur piutang (`receivables` domain vs `customer_payments` legacy), memindahkan **harga produk/unit** (`products.price/buy_price/purchase_price/price_grosir`, `product_units.*`) ke minor unit (masih desimal major — laporan margin masih membacanya), adaptor payment gateway/QRIS dinamis (menunggu penyedia), OCR (FR-03), barcode kamera, multi-cabang/transfer stok, dokumentasi Fase 6, dead code `app/Models/Recipe.php`, `RolePermissionSeeder` legacy.

---

# Rebuild POS Universal — Fase 5: Data Demo Ber-tenant & Minor Unit (Seeder) (Increment 56) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§12 isolasi tenant; §17.1 nilai uang integer minor unit; FR-07 piutang & penagihan; FR-05 aturan pembayaran; §11.1 idempotensi). Perintah operasional: `php artisan db:seed`.

## Latar
Perintah yang paling dasar untuk mencoba aplikasi — `php artisan db:seed` — ternyata **gagal total** dan sisanya menghasilkan data yang tidak berguna. Tiga cacat bertumpuk:
1. `PurchaseOrderSeeder` menulis `purchase_orders.total_amount`, kolom yang sudah dihapus `2026_10_12_000003_finish_purchase_sync_fields` → `SQLSTATE[42S22] Unknown column 'total_amount'` dan seeder berhenti di situ, sehingga semua seeder setelahnya (stok, opname, retur, pembayaran, pengaturan) **tidak pernah dijalankan**.
2. Seluruh baris demo lahir dengan `tenant_id` NULL. Scope/isi tenant otomatis (`BelongsToTenant`) hanya bekerja bila `TenantContext` terikat; CLI seeding tidak mengikat siapa pun. Akibatnya data demo tak akan pernah terlihat di UI — setiap permintaan selalu disaring ke tenant aktif.
3. Seluruh kolom `*_minor` bernilai 0 (transaksi, item, pesanan pembelian, retur) sehingga laporan & piutang demo tampak kosong; `ReturnSeeder` bahkan menulis nilai MINOR ke kolom desimal legacy MAJOR (retur tampil 100× di layar lama); `supplier_payments` selalu nol karena kolom `amount` bukan fillable dan `$po->total_amount` sudah tiada.

Ditemukan saat menguji Increment 55 di database MySQL terpisah (`seedercheck`), bukan dari asumsi.

## Keputusan desain
- **Tenant dulu, baru data.** Trait `SeedsDemoTenant` mengikat `TenantContext` ke tenant default (dan memanggil `TenantSeeder` bila belum ada), dipakai sepuluh seeder + `DatabaseSeeder`. Ini membuat tiap seeder dapat dijalankan sendiri (`php artisan db:seed --class=ProductSeeder`) tanpa bergantung urutan, dan menjamin seluruh baris ber-tenant.
- **Transaksi demo lahir dari jalur domain, bukan penulisan kolom.** `TransactionSeeder` memakai `CreateSale` — satu-satunya penulis yang boleh menghitung total, memotong stok, dan membuka piutang. Demonstrasi pun menegakkan aturan nyata: saat seeder mengirim kelebihan bayar lewat non-tunai, domain menolak (`kembalian hanya dari tunai`), dan seeder disesuaikan. Yang di-*backdate* hanya metadata waktu (`transactions.created_at`), bukan nilai uang/stok.
- **Penagihan piutang demo memakai `RecordReceivablePayment`** (FR-07) sehingga `remaining_minor` dan baris pembayaran konsisten — bukan diedit langsung (aturan "saldo piutang tidak boleh diubah dengan mengedit angka").
- **Cermin desimal ditulis lewat `Money`**, bukan aritmetika mentah, sehingga tabel penjualan yang masih punya kolom desimal legacy tetap benar untuk layar lama tanpa bergantung pada `minor_exponent` (saat ini 0).
- **Semua seeder demo dibuat idempoten** (`firstOrCreate` untuk data master, guard `count()` untuk data transaksional, user demo idempoten) supaya `db:seed` aman dijalankan ulang, mis. setelah `migrate:fresh` sebagian.

## Perubahan
- **`database/seeders/Concerns/SeedsDemoTenant.php`** (baru) — pengikat tenant untuk seeder CLI.
- **`database/seeders/DatabaseSeeder.php`** — mengikat tenant sebelum data ditulis; user `admin`/`pemilik` dibuat idempoten (`firstOrCreate` by email).
- **`database/seeders/TransactionSeeder.php`** — ditulis ulang memakai `CreateSale`; ~25% transaksi kredit (membuka piutang demo), tanggal disebar 30 hari terakhir, menghormati ketersediaan stok.
- **`database/seeders/PurchaseOrderSeeder.php`** — hanya kolom `*_minor` (legacy sudah dihapus); harga dari `purchase_price`/`buy_price` dikonversi via `Money`.
- **`database/seeders/ReturnSeeder.php`** — retur jual: `*_minor` sebagai sumber kebenaran + cermin desimal major yang benar; retur beli: hanya `*_minor` (sebelumnya nol semua).
- **`database/seeders/PaymentSeeder.php`** — penagihan piutang via `RecordReceivablePayment`; `supplier_payments.amount_minor`; cicilan `customer_payments` dihitung dari nilai minor.
- **`database/seeders/{Brand,Category,Supplier,Customer,Product}Seeder.php`** — idempoten (`firstOrCreate`).
- **`database/seeders/{StockMovement,StockOpname}Seeder.php`** — mengikat tenant sebelum guard.
- **`tests/Feature/Database/DemoSeederTest.php`** (baru) — 8 tes kontrak data demo.

## Pengujian
- **`tests/Feature/Database/DemoSeederTest.php`** (8) — seeder mengisi toko demo; **setiap** baris (17 tabel) ber-tenant dan tak ada `tenant_id` NULL; uang penjualan tersimpan minor & cermin legacy konsisten (`Money`); saldo piutang cocok dengan jumlah pembayarannya (SQL silang); uang pembelian/retur beli minor & tidak nol; retur jual cermin minor; seeder individual tetap mengikat tenant; `DatabaseSeeder` **idempoten** (dijalankan dua kali, jumlah baris tetap).
- Diverifikasi juga di **MySQL produksi** (database terpisah `seedercheck`, lalu dihapus): `db:seed` dua kali idempoten, 0 baris tanpa tenant, cermin legacy == minor, dan `remaining_minor = original_amount_minor − Σ pembayaran`.

## Status suite
- **914 passed / 0 failed / 4 skipped** (3660 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 18 file, 1 perbaikan indentasi.
- Sisa pekerjaan Fase 5: menyatukan dua jalur piutang (`receivables` domain vs `customer_payments` legacy), memindahkan pembaca UI lama (dashboard/laporan/struk) ke minor unit lalu menghapus kolom desimal legacy tabel penjualan, memindahkan harga produk/unit ke minor unit, adaptor payment gateway/QRIS dinamis (menunggu penyedia), OCR (FR-03), barcode kamera, multi-cabang/transfer stok, dokumentasi Fase 6, dead code `app/Models/Recipe.php`, `RolePermissionSeeder` legacy.

---

# Rebuild POS Universal — Fase 5: Endpoint Sisa API §18 — Pembayaran Piutang & Laporan Penjualan + Pengetatan Idempotensi (Increment 55) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§18 daftar endpoint: `POST /api/v1/receivables/{id}/payments`, `GET /api/v1/reports/sales`; §12 isolasi tenant; §17.1 nominal integer minor unit; §11.1 idempotensi `client_request_id`; FR-07 piutang & catatan pembayaran; FR-09 definisi laporan penjualan & §17.4 void; FR-12 izin/audit; FR-13 modul; §15 token perangkat ≠ token pengguna).

## Latar
Increment 52–54 membuka login, katalog, dan seluruh jalur penjualan. Dua endpoint §18 masih hilang: penagihan piutang dari klien perangkat (FR-07) dan laporan penjualan (FR-09). Saat menulisnya, pola cacat yang sama seperti Increment 54 ditemukan di lima aksi domain lain: pencarian kunci idempotensi memakai `withoutGlobalScopes()` **tanpa** batas tenant, sehingga kunci milik tenant lain dapat mengembalikan record tenant lain. Pengetatan itu dikerjakan sekaligus di increment ini.

## Keputusan desain
- **Laporan tidak menghitung ulang apa pun di lapisan API**: controller memanggil use case yang sama dengan UI (`App\Reports\SalesReport`), sehingga hanya ada SATU sumber kebenaran untuk FR-09 (omzet ≠ penerimaan ≠ piutang baru; transaksi batal tidak dihitung). Kunci balasan mengikuti istilah domain (`omzet_minor`, `penerimaan_minor`, `piutang_baru_minor`, `dibatalkan_minor`) agar tidak lahir kamus kedua.
- **Izin & audit laporan tetap di use case** (FR-12): `report.financial.view` diperiksa `ActionAuthorizer` dan akses yang benar-benar terjadi dicatat `report.financial.viewed` dengan aktor + periode. Endpoint API tidak menyalin logika itu.
- **Modul nonaktif = 403, bukan data kosong** (FR-13), konsisten dengan Increment 53: `reports` untuk laporan, `receivables` untuk pembayaran piutang (modul piutang memang hanya ada di profil `credit_sales`/`advanced`).
- **Idempotensi pembayaran piutang** (§11.1): `client_request_id` wajib; penulisan baru → **201**, balasan ulang → **200** dengan `sync.status = already_applied` + `sync.idempotent_replay = true`, tanpa menambah baris pembayaran atau menurunkan sisa saldo dua kali.
- **Kebenaran saldo tetap milik domain**: nominal ≤ 0, melebihi sisa, atau piutang yang sudah lunas ditolak aksi domain (422 dengan pesan Indonesia), bukan diperiksa ulang di controller. Respons menyertakan sisa saldo TERBARU (`receivable.remaining_minor` + `status`) agar klien tidak menghitung sendiri.
- **Pengetatan idempotensi §12 diperluas**: `RecordReceivablePayment`, `CreatePurchaseOrder`, `CreatePurchaseReturn`, `ReceiveGoods`, `RecordSupplierPayment`, dan `CreateSalesReturn` kini mencari kunci DI DALAM tenant pemanggil; migrasi mengganti unik global `client_request_id` pada `receivable_payments` & `sales_returns` dengan unik gabungan `(tenant_id, client_request_id)` (hanya indeks — non-destruktif).
- **Paritas skema untuk tes**: `2026_10_12_000003_finish_purchase_sync_fields` menghapus kolom uang legacy hanya pada MySQL (memakai `SHOW COLUMNS`), sehingga di sqlite kolom `NOT NULL` tanpa default itu masih ada dan membuat aksi domain gagal di tes padahal benar di produksi. Migrasi baru menutup selisih itu memakai `Schema::hasColumn()` di semua driver — bukan bug produksi, tetapi membuat tes benar-benar menguji skema yang dipakai produksi.

## Perubahan
- **`app/Support/Api/ReceivableLocator.php`** — pencarian piutang satu tenant (UUID sinkronisasi atau id numerik) + pencarian pembayaran berdasarkan kunci idempotensi, keduanya dengan batas `tenant_id` eksplisit.
- **`app/Support/Api/ReceivablePaymentPayload.php`** — bentuk kiriman pembayaran piutang (nominal minor, metode + label Indonesia, aktor, catatan, `paid_at`, status pembalikan).
- **`app/Http/Requests/Api/V1/StoreReceivablePaymentRequest.php`** — validasi kontrak pembayaran; `payment_method` hanya metode yang boleh DIPILIH (`mixed` dikecualikan, FR-05); `device_id` wajib milik tenant & belum dicabut; pesan Indonesia per medan.
- **`app/Http/Controllers/Api/V1/ReceivablePaymentController.php`** — `POST /api/v1/receivables/{receivable}/payments` (201 baru, 200 balasan ulang, 404 piutang tenant lain/tidak ada, 403 modul nonaktif).
- **`app/Http/Requests/Api/V1/SalesReportRequest.php`** — `date` XOR `from`/`to`, format `Y-m-d`.
- **`app/Http/Controllers/Api/V1/SalesReportController.php`** — `GET /api/v1/reports/sales` (satu hari atau rentang; default hari ini).
- **`app/Actions/Receivables/RecordReceivablePayment.php`**, **`app/Actions/Purchases/CreatePurchaseOrder.php`**, **`CreatePurchaseReturn.php`**, **`ReceiveGoods.php`**, **`RecordSupplierPayment.php`**, **`app/Actions/Sales/CreateSalesReturn.php`** — pencarian kunci idempotensi dibatasi tenant (§12).
- **`database/migrations/2026_10_13_220000_scope_remaining_idempotency_keys_to_tenant.php`** — unik idempotensi per tenant untuk `receivable_payments` & `sales_returns`.
- **`database/migrations/2026_10_13_230000_drop_legacy_money_columns_for_schema_parity.php`** — kolom uang legacy dibersihkan di semua driver, bukan hanya MySQL.
- **`routes/api.php`** — dua endpoint di grup `auth:sanctum` + `user.tenant`.

## Pengujian
- **`tests/Feature/Api/ReceivablePaymentApiTest.php`** (9) — penagihan normal lewat alur nyata (checkout kredit API → bayar) menurunkan sisa saldo & menulis satu baris pembayaran; pelunasan menutup piutang (`status = closed`); balasan ulang `client_request_id` → 200 & tidak menagih dua kali; melebihi sisa → 422; piutang lunas → 422; piutang tenant lain & rujukan tidak dikenal → 404 tanpa penulisan; pelanggaran kontrak dilaporkan per medan (`mixed` & `device_id` asing ditolak); modul piutang nonaktif → 403; tanpa token → 401 dan **token perangkat ditolak** (§15).
- **`tests/Feature/Api/SalesReportApiTest.php`** (8) — laporan hari ini default memuat omzet/penerimaan/jumlah transaksi/metode pembayaran/riwayat + amplop meta; `date` dan `from`/`to` menyaring periode (termasuk penjualan yang di-*backdate*); penjualan yang dibatalkan tidak dihitung di omzet tetapi tetap tampil di riwayat bersama alasannya (§17.4); izin: `inventory_manager` → 403 TANPA jejak audit, `viewer` → 200 DENGAN audit `report.financial.viewed` atas namanya (FR-12); modul laporan nonaktif → 403; format tanggal salah → 422; penjualan tenant lain tidak ikut terhitung di kedua arah (§12); tanpa token → 401 dan token perangkat ditolak (§15).
- **`tests/Feature/Tenancy/IdempotencyTenantScopeTest.php`** (6) — satu kunci yang sama dipakai di dua tenant untuk enam aksi tulis (piutang, retur penjualan, pesanan pembelian, penerimaan barang, pembayaran pemasok, retur pembelian): hasilnya SELALU record milik tenant pemanggil, dengan pemeriksaan tambahan sisa saldo, jumlah baris pembayaran, nomor pesanan, dan jumlah item retur.

## Status suite
- **906 passed / 0 failed / 4 skipped** (3323 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 18 file, tanpa perbaikan gaya.
- Daftar endpoint §18 kini **lengkap**: `auth/login`, `devices/register`, `bootstrap`, `products`, `sales` (+ rincian & void), `receivables/{id}/payments`, `reports/sales`, `sync/push`, `sync/pull`, `payments/webhooks/{provider}`.
- Utang lain: adaptor payment gateway/QRIS dinamis (menunggu pilihan penyedia), OCR (FR-03), barcode kamera, multi-cabang/transfer stok (stok masih tunggal per tenant), dokumentasi Fase 6, minor-unit data demo (`TransactionSeeder`/`PaymentSeeder`/dll masih menulis kolom lama), `RolePermissionSeeder` legacy, dead code `app/Models/Recipe.php`.

---

# Rebuild POS Universal — Fase 5: Endpoint Tulis API — Checkout, Rincian, & Void Penjualan (§18, Increment 54) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§18 daftar endpoint: `POST /api/v1/sales`, `GET /api/v1/sales/{id}`, `POST /api/v1/sales/{id}/void` + "Contoh kontrak checkout" baris ~1181; §12 isolasi tenant; §17.1 harga integer minor unit; §17.4 void tidak menghapus & alasan wajib; §11.1 idempotensi; FR-05 metode pembayaran; FR-07 piutang; FR-12 izin; FR-13 modul).

## Latar
Setelah Increment 53 klien sudah bisa membaca (bootstrap + katalog) tetapi belum bisa MENULIS apa pun: kasir tidak dapat menyelesaikan transaksi, mengambil rinciannya, atau membatalkannya. Increment ini membuka jalur tulis §18 dan sekaligus menutup satu cacat isolasi tenant pada idempotensi penjualan yang ditemukan saat mengerjakannya.

## Keputusan desain
- **Harga TIDAK PERNAH dipercaya dari klien** (§18 "Nilai harga yang dikirim client tidak boleh dipercaya sebagai sumber final"): kontrak permintaan hanya memuat `items[].product_id` + `quantity`; `SaleCheckoutMapper` mengambil harga dari master (harga satuan bila `product_unit_id` dikirim, jika tidak harga satuan dasar) DI DALAM transaksi basis data yang sama dengan penulisannya. Medan `unit_price_minor` yang disisipkan klien diabaikan (diuji).
- **Kebijakan toko bukan permintaan perangkat**: `allow_negative_stock` tidak diterima dari klien (kebijakan `pos.inventory.allow_negative_stock`, §11.4); status verifikasi pembayaran juga tidak dapat diklaim klien (FR-05).
- **Idempotensi berlapis** (§11.1): `client_request_id` wajib; `uuid` opsional (identitas sinkronisasi perangkat). Balasan ulang mengembalikan penjualan yang sama dengan `sync.status = already_applied` dan `sync.idempotent_replay = true`, tanpa menulis stok/mutasi/audit kedua.
- **Cacat isolasi tenant diperbaiki** (ditemukan saat menulis endpoint ini): `CreateSale` mencari `client_request_id` **tanpa batas tenant** (`withoutGlobalScopes()`), sehingga kunci milik tenant lain dapat mengembalikan penjualan tenant lain. Pencarian kini dibatasi `tenant_id`; migrasi `2026_10_13_214500_*` mengganti indeks unik global `client_request_id` dengan unik gabungan `(tenant_id, client_request_id)` (hanya indeks — tidak ada kolom/baris yang diubah).
- **`uuid` unik global**: bila perangkat mengirim uuid yang sudah dimiliki tenant lain, permintaan dijawab **409** — bukan 500 dan bukan data tenant lain (§12).
- **Otorisasi FR-12**: `access.module` selalu; `discount.apply` bila ada diskon (baris atau transaksi) sehingga kasir tanpa izin tidak bisa memotong harga; `transaction.void` diperiksa `VoidSale` atas nama pengguna token.
- **Modul kasir & sisi server**: modul `cashier` nonaktif → 403 (FR-13); `enqueue_for_sync` dimatikan karena penjualan sudah lahir di server (§11.1).
- **`GET /api/v1/sales/{id}`** menerima UUID sinkronisasi atau id numerik, selalu dibatasi tenant; penjualan tenant lain dijawab 404 yang sama dengan penjualan tidak ada (tidak membocorkan keberadaan).

## Perubahan
- **`app/Support/Api/SaleCheckoutMapper.php`** — kontrak §18 → data `CreateSale`: resolusi harga server (produk wajib aktif, satuan wajib milik produk itu), pemetaan `discount {type, value}` (nominal dalam minor unit / persen), `payments[].method` → tender, jalur `paid_minor` untuk kredit tanpa uang muka.
- **`app/Support/Api/SalePayload.php`** — bentuk kiriman penjualan (id, uuid, nomor struk, status, total minor, kembalian, sisa, item, tender, piutang, label Indonesia).
- **`app/Support/Api/SaleLocator.php`** — pencarian penjualan satu tenant (UUID atau id) + pencarian lintas tenant khusus pemeriksaan bentrokan uuid.
- **`app/Http/Requests/Api/V1/StoreSaleRequest.php`** — validasi kontrak checkout; `device_id`/`location_id`/`customer_id` wajib milik tenant dan perangkat belum dicabut; pesan bahasa Indonesia per medan.
- **`app/Http/Requests/Api/V1/VoidSaleRequest.php`** — alasan void wajib (3–500 karakter), `restore_stock` opsional.
- **`app/Http/Controllers/Api/V1/StoreSaleController.php`** — `POST /api/v1/sales` (201 dibuat, 200 balasan ulang, 409 uuid bentrok).
- **`app/Http/Controllers/Api/V1/ShowSaleController.php`** — `GET /api/v1/sales/{sale}`.
- **`app/Http/Controllers/Api/V1/VoidSaleController.php`** — `POST /api/v1/sales/{sale}/void`.
- **`app/Actions/Sales/CreateSale.php`** — idempotensi dibatasi tenant (§12).
- **`app/Exceptions/Payments/QrisNotConfigured.php`** — `render()` 422 (QRIS belum diunggah = kesalahan pemanggil, bukan kegagalan server); jalur web sudah menangkapnya lebih dulu sehingga tidak berubah.
- **`database/migrations/2026_10_13_214500_scope_sale_idempotency_key_to_tenant.php`** — unik idempotensi per tenant.
- **`routes/api.php`** — tiga endpoint penjualan di grup `auth:sanctum` + `user.tenant`.

## Pengujian
- **`tests/Feature/Api/SaleApiTest.php`** (20) — penjualan tunai dengan harga server & minor unit (stok, item, tender); harga kiriman klien diabaikan; balasan ulang `client_request_id` tidak menggandakan (stok tetap, satu transaksi); uuid perangkat dihormati & di-*replay*; uuid milik tenant lain → 409 tanpa penulisan; kunci idempotensi yang sama di tenant lain tetap membuat penjualan MILIK tenant itu (bukti perbaikan isolasi) dan silang-baca ditolak; penjualan kredit membuat piutang dengan jatuh tempo (`due_days`); kredit wajib pelanggan tenant sendiri; produk nonaktif/tenant lain ditolak; stok tidak cukup → pesan domain; diskon butuh izin `discount.apply`; diskon memotong total; pelanggaran kontrak dilaporkan per medan; QRIS belum dikonfigurasi → 422; rincian via UUID & id, yang tidak ada → 404; void mengembalikan stok SEKALI & idempoten; void butuh izin `transaction.void` + alasan; void atas penjualan tenant lain → 404; modul kasir nonaktif → 403; tanpa token → 401 dan **token perangkat ditolak** di endpoint pengguna (§15).
- Catatan tes: guard auth dan `TenantContext` adalah `singleton` sehingga antar-permintaan di dalam satu tes harus direset (`freshRequestState()`) agar tidak meniru kebocoran identitas yang tidak terjadi pada permintaan sungguhan.

## Status suite
- **883 passed / 0 failed / 4 skipped** (3168 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 29 file, 1 perbaikan gaya.
- Endpoint §18 yang belum ada: `POST /api/v1/receivables/{id}/payments`, `GET /api/v1/reports/sales`.
- Utang lain: adaptor payment gateway/QRIS dinamis (menunggu pilihan penyedia), OCR (FR-03), multi-cabang/transfer stok, dokumentasi Fase 6, minor-unit data demo.

---

# Rebuild POS Universal — Fase 5: Endpoint Baca API — Bootstrap & Katalog Produk (§18, Increment 53) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§18 daftar endpoint: `GET /api/v1/bootstrap`, `GET /api/v1/products`; §12 isolasi tenant; §17.1 harga integer minor unit; FR-13 profil fitur; FR-05 metode pembayaran; FR-12 izin `access.module`).

## Latar
Setelah Increment 52 klien perangkat sudah bisa login dan mendaftarkan diri, tetapi belum ada satu pun endpoint baca: klien tidak tahu toko mana yang dilayaninya, modul apa yang aktif, kebijakan apa yang berlaku, dan tidak bisa mengambil katalog produk. Increment ini menutup celah itu.

## Keputusan desain
- **Endpoint baca memakai token PENGGUNA + izin FR-12 `access.module`** (semua peran punya izin ini), bukan token perangkat. Sinkronisasi tetap jalur token perangkat; pemisahan ini diuji sejak Increment 52.
- **Tenant dari pengguna, tidak pernah dari permintaan** (§12): middleware baru `BindUserTenant` mengikat `TenantContext` dari `$request->user()->tenant_id` setelah `auth:sanctum`. Tanpa ikatan ini, scope tenant global tidak aktif dan query produk bisa membaca data tenant lain — celah yang ditutup dan **diuji langsung** (produk tenant tetangga tidak muncul).
- **Modul nonaktif = 403, bukan data kosong** (FR-13): katalog produk menolak dengan pesan jelas bila modul `products` dimatikan untuk toko itu.
- **Harga selalu dari server sebagai integer minor unit** (§17.1/§18), dan `buy_price`/`purchase_price` TIDAK dikirim — informasi margin bukan kebutuhan kasir.
- **`GET /api/v1/products` mendukung penyegaran inkremental** (`updated_since`) agar klien offline tidak mengunduh seluruh katalog berulang kali; paginasi dibatasi 200/halaman.

## Perubahan
- **`app/Http/Middleware/BindUserTenant.php`** (alias `user.tenant`) — mengikat tenant pengguna terautentikasi ke `TenantContext` (§12).
- **`bootstrap/app.php`** — daftarkan alias middleware.
- **`app/Support/Api/ApiMeta.php`** — amplop respons seragam: `server_time`, `currency` (kode + minor exponent), `version` (app & schema) — klien dapat mendeteksi jam perangkat yang melenceng dan menerjemahkan minor unit tanpa menebak.
- **`app/Support/Api/ProductPayload.php`** — pemetaan produk → JSON API (harga `price_minor`, unit dasar, kategori/merek ringkas, `version`, `updated_at`; daftar unit penuh bila diminta).
- **`app/Http/Controllers/Api/V1/BootstrapController.php`** — `GET /api/v1/bootstrap`: identitas tenant (profil fitur, kompleksitas UI, currency, timezone, onboarding) + pengguna (peran & izin), `modules` efektif (FR-13), `payment_methods` yang boleh dipilih (`mixed` dikecualikan, FR-05), lokasi aktif tenant (§12, dipakai saat mendaftarkan perangkat), dan `policies` (piutang FR-07, stok negatif §11.4, kebijakan stok void/retur §17.4, batas batch sinkronisasi §11).
- **`app/Http/Controllers/Api/V1/ProductIndexController.php`** + **`app/Http/Requests/Api/V1/ProductIndexRequest.php`** — `GET /api/v1/products`: filter `search` (nama/SKU/barcode, wildcard di-escape), `category_id`, `updated_since`, `include_inactive`, `include_units`, `sort`/`direction` (whitelist), `per_page` (maks 200) + meta paginasi.
- **`routes/api.php`** — grup `auth:sanctum` + `user.tenant` kini memuat `bootstrap` dan `products`.

## Pengujian
- **`tests/Feature/Api/BootstrapApiTest.php`** (6) — isi bootstrap (tenant, pengguna, izin, `currency.minor_exponent`, kebijakan mengikuti konfigurasi, `mixed` tidak ada di pilihan metode); hanya lokasi AKTIF milik tenant sendiri yang dikirim (lokasi nonaktif & tenant tetangga tidak muncul); `enabled_modules` eksplisit menimpa profil; 401 tanpa token; 403 tanpa izin `access.module`; 403 pengguna tanpa toko.
- **`tests/Feature/Api/ProductIndexApiTest.php`** (8) — hanya produk tenant pengguna yang muncul (produk tenant lain tidak bocor) + harga minor unit + unit dasar + meta; produk nonaktif tersembunyi kecuali `include_inactive`; pencarian nama/SKU/barcode; `updated_since` untuk penyegaran inkremental; paginasi & batas `per_page` (422 bila berlebih); `include_units` mengembalikan seluruh satuan; modul produk nonaktif → 403; 401 tanpa token.

## Status suite
- **863 passed / 0 failed / 4 skipped** (3022 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 12 file, tanpa perbaikan gaya.
- Endpoint §18 yang belum ada: `POST /api/v1/sales`, `GET /api/v1/sales/{id}`, `POST /api/v1/sales/{id}/void`, `POST /api/v1/receivables/{id}/payments`, `GET /api/v1/reports/sales`.
- Utang lain: adaptor payment gateway/QRIS dinamis (menunggu pilihan penyedia), OCR (FR-03), multi-cabang/transfer stok, dokumentasi Fase 6, minor-unit data demo.

---

# Rebuild POS Universal — Fase 5: Fondasi API Klien Perangkat — Sanctum, Login, & Pendaftaran Perangkat (§18, Increment 52) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§18 "Endpoint inti": `POST /api/v1/auth/login`, `POST /api/v1/devices/register`; §15 "Autentikasi server menggunakan mekanisme yang didukung Laravel", "Perangkat memiliki ID dan token unik", "Perangkat yang dicabut harus ditolak"; §12 "Jangan mengandalkan `tenant_id` dari request pengguna tanpa memverifikasinya"; NFR-03 keamanan; FR-12 izin).

## Latar
Fase 4 hanya menyediakan endpoint sinkronisasi (`sync/push`, `sync/pull`) yang diautentikasi **token perangkat**. Daftar endpoint §18 yang lain (`auth/login`, `devices/register`, `sales`, `products`, laporan, pembayaran piutang) belum ada, dan belum ada satu pun mekanisme yang mengautentikasi **manusia** di API — sehingga perangkat tidak punya cara resmi memperoleh identitasnya sendiri. Increment ini memasang fondasinya: token pengguna (Sanctum) untuk identitas manusia, token perangkat (tabel `devices`, sudah ada) tetap untuk sinkronisasi.

## Keputusan desain
- **Sanctum (`laravel/sanctum` ^4.3, paket first-party Laravel)** — sesuai §15; mendukung Laravel 13 (`illuminate/* ^13.0`). Dependensi dipasang lokal di proyek (bukan global); `composer.json`/`composer.lock`/`config/auth.php` dicadangkan lebih dulu ke `Backups/projects/retailflow-*.pre-sanctum-20261013`.
- **Dua identitas, dua endpoint, satu arah**: `auth/login` mengembalikan token **pengguna** (ability = izin FR-12 pengguna itu); `devices/register` (memakai token pengguna + izin `user.manage`) mengembalikan token **perangkat** yang dipakai sinkronisasi. Token pengguna TIDAK berlaku sebagai token perangkat dan sebaliknya — diuji.
- **Tenant selalu dari identitas, tidak dari body** (§12): login mengambil tenant dari pengguna, pendaftaran perangkat mengambil tenant dari pengguna terautentikasi, lokasi wajib milik tenant itu.
- **Masa berlaku token** dikonfigurasi (`pos.api.token_days`, default 30 hari; `0` = tanpa kedaluwarsa) — token bocor tidak berlaku selamanya; token kedaluwarsa dibuang penjadwal harian `sanctum:prune-expired`.
- **Urutan pemeriksaan login**: kredensial diperiksa lebih dulu, baru status akun, sehingga status akun hanya terungkap kepada pemegang kredensial yang sah.

## Perubahan

### Dependensi & skema
- **`composer.json`** — tambah `laravel/sanctum` ^4.3.
- **`database/migrations/2026_10_13_213726_create_personal_access_tokens_table.php`** — migrasi resmi Sanctum (dipublikasikan agar terlihat & ikut ditinjau; sebelumnya package tidak memuat migrasinya sendiri).
- **`app/Models/User.php`** — trait `HasApiTokens`.
- **`config/pos.php`** — bagian `pos.api`: `token_days` (env `POS_API_TOKEN_DAYS`, default 30) dan `login_attempts_per_minute` (env `POS_API_LOGIN_ATTEMPTS`, default 10).

### Domain & API
- **`Actions\Auth\IssueApiToken`** — menukar kredensial dengan token API: pencarian pengguna lintas tenant (`withoutGlobalScope('tenant')`, email unik global) agar hasil login tak bergantung konteks permintaan; `Hash::check` dulu lalu tolak akun nonaktif/tanpa toko; ability token = izin FR-12 pengguna; **login ulang pada nama perangkat yang sama mencabut token lama perangkat itu** (sesi tidak menumpuk); audit `api.login` (tanpa secret).
- **`Exceptions\Auth\LoginRejected`** — kredensial salah 422, akun nonaktif / tanpa toko 403.
- **`Support\Auth\ApiSession`** — hasil login (pengguna, tenant, token plaintext sekali, kedaluwarsa, ability, peran).
- **`Http\Controllers\Api\V1\LoginController`** — `POST /api/v1/auth/login` (dibatasi laju `throttle:pos.api.login_attempts_per_minute,1`) mengembalikan token, kedaluwarsa, pengguna, tenant (profil fitur/currency/timezone), peran, izin, waktu server.
- **`Http\Controllers\Api\V1\DeviceRegisterController`** — `POST /api/v1/devices/register` (middleware `auth:sanctum`) memakai use case `RegisterDevice` yang sudah ada (izin `user.manage`, token perangkat plaintext sekali, audit `device.registered`).
- **`Http\Requests\Api\V1\{LoginRequest,RegisterDeviceRequest}`** — validasi + pesan Indonesia; `location_id` dibatasi `exists:locations,id,tenant_id=pengguna` sehingga lokasi tenant lain ditolak sebelum menyentuh domain.
- **`routes/api.php`** — grup `auth:sanctum` untuk endpoint yang bertindak atas nama manusia; grup `device` tetap untuk sinkronisasi; dokumentasi dua identitas di header file.
- **`routes/console.php`** — penjadwal harian `sanctum:prune-expired --hours=24`.

### Pengujian
- **`tests/Feature/Api/ApiLoginTest.php`** (9) — token + ability izin + audit + hanya hash yang tersimpan + kedaluwarsa ±30 hari; kredensial salah (email tak dikenal dijawab pesan sama, tanpa token/audit); akun nonaktif 403; pengguna tanpa toko 403; `device_name`/`platform` wajib; pembatasan laju (429 setelah batas); login ulang nama perangkat sama mencabut token lama (token lama 401, token baru 201); `token_days=0` → tanpa kedaluwarsa; token pengguna ditolak di endpoint sinkronisasi (401).
- **`tests/Feature/Api/DeviceRegisterApiTest.php`** (7) — pendaftaran sukses + token perangkat langsung bisa dipakai `sync/pull` + audit; lokasi milik tenant sendiri diterima; lokasi tenant lain 422; peran tanpa `user.manage` (kasir) 403; tanpa token 401; pengguna nonaktif 403; token pengguna yang sudah dicabut 401.

## Status suite
- **849 passed / 0 failed / 4 skipped** (2962 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 17 file, 2 perbaikan gaya.
- Endpoint §18 yang belum ada: `GET /api/v1/bootstrap`, `GET /api/v1/products`, `POST /api/v1/sales`, `GET /api/v1/sales/{id}`, `POST /api/v1/sales/{id}/void`, `POST /api/v1/receivables/{id}/payments`, `GET /api/v1/reports/sales`.
- Utang lain: adaptor payment gateway/QRIS dinamis (menunggu pilihan penyedia), OCR (FR-03), multi-cabang/transfer stok, dokumentasi Fase 6, dan minor-unit pada data demo (`TransactionSeeder` belum mengisi `*_minor`).

---

# Rebuild POS Universal — Fase 5: Catatan Penagihan Piutang (FR-07, Increment 51) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (FR-07 daftar fitur: "Catatan penagihan").

## Latar
Increment 50 menutup dua kewajiban FR-07 (jatuh tempo + batas kredit). Satu item FR-07 masih kosong: **catatan penagihan** — tidak ada tempat mencatat hasil penagihan (dihubungi via apa, kapan, hasilnya apa), sehingga riwayat penagihan hanya hidup di ingatan kasir/admin. Sisa FR-07 lain ("daftar piutang jatuh tempo") sudah terjawab laporan umur piutang yang kini bermakna karena `due_at` sudah terisi sejak Increment 50.

## Perubahan

### Domain
- **`database/migrations/2026_10_13_000004_create_receivable_notes_table.php`** — tabel `receivable_notes` (aditif): `uuid`, `tenant_id`, `receivable_id`, `channel`, `note` (1000), `contacted_at`, `created_by`, `version`.
- **`Models\ReceivableNote`** — `BelongsToTenant` + `HasSyncIdentity` + `JournalSyncChanges` (konsisten dengan entitas domain lain), relasi `receivable()` & `author()`.
- **`Actions\Receivables\AddReceivableNote`** — satu-satunya jalur pencatatan penagihan. Menegakkan: catatan wajib (kosong ditolak), saluran divalidasi terhadap enum, piutang dicari dengan `withoutGlobalScopes` + filter `tenant_id` eksplisit (catatan tak bisa menempel ke piutang tenant lain), **append-only** (tanpa jalur ubah/hapus), `contacted_at` default `now()` bila tak diisi, satu baris audit `receivable.note_added`, semuanya atomik.
- **`Enums\CollectionChannel`** — telepon, WhatsApp, SMS, kunjungan, surat, lainnya (+ `label()` bahasa Indonesia) agar UI dan validasi memakai daftar yang sama.
- **`Exceptions\Receivables\CollectionNoteNotAllowed`** — catatan kosong / piutang tak ditemukan / saluran tak dikenal (+ respons JSON 422).

### UI
- **`resources/views/pages/customers/[Customer]/show.blade.php`** — kartu **"Collection Notes"** (riwayat penagihan pelanggan: waktu kontak, badge saluran, nomor invoice, siapa yang menagih, isi catatan; 50 terbaru) plus aksi **"Add Collection Note"** pada menu tiap piutang terbuka → modal (isi catatan, saluran, tanggal dihubungi). Guard: modal hanya bisa dibuka untuk piutang milik pelanggan halaman itu (`abort_unless` 404), dan aksi simpan memvalidasi ulang (isi ≥ 2 karakter, tanggal sah).

### Pengujian
- **`tests/Feature/Receivables/CollectionNoteTest.php`** (8) — pencatatan lengkap + audit, `contacted_at` default `now()`, saluran opsional, catatan kosong ditolak, piutang tak ada ditolak, saluran tak dikenal ditolak, append-only (2 catatan tersimpan berurutan), batas tenant (piutang tenant lain → ditolak).
- **`tests/Feature/Customers/CollectionNoteUiTest.php`** (4) — halaman menampilkan catatan yang ada (teks, invoice, label saluran), Owner mencatat catatan lewat UI (state + basis data + audit + tampil pada mount ulang), catatan kosong ditolak validasi & tidak tersimpan, catatan tak bisa menyasar piutang pelanggan lain (404).
- Catatan teknis untuk pengujian berikutnya: isi halaman detail pelanggan dirender sebagai **fragmen Volt anak**, sehingga `html()` setelah aksi tidak memuat body halaman — assertSee harus dilakukan pada mount (pola sama dengan yang ditemukan di Increment 50 untuk halaman POS).

## Status suite
- **833 passed / 0 failed / 4 skipped** (2886 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 9 file, 1 perbaikan gaya.
- Dengan ini seluruh daftar fitur FR-07 sudah terwujud. Utang yang masih terbuka: adaptor payment gateway/QRIS dinamis (menunggu pilihan penyedia), OCR (FR-03), multi-cabang/transfer stok, endpoint §18 untuk klien perangkat, dokumentasi Fase 6, dan pemeriksaan minor-unit pada data demo (`TransactionSeeder` belum mengisi `*_minor`).

---

# Rebuild POS Universal — Fase 5: Batas Kredit & Jatuh Tempo Piutang (FR-07, Increment 50) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (FR-07 "Tanggal jatuh tempo", "Batas kredit dan peringatan pelanggan dapat dikonfigurasi per toko", "Piutang hanya terbentuk dari transaksi yang memiliki saldo belum dibayar"; §17.3 langkah 6 "piutang lahir dari saldo belum dibayar"; FR-09 laporan umur piutang).

## Latar
Setelah Increment 49 (koreksi pembayaran ber-audit), dua kewajiban FR-07 masih kosong: **tanggal jatuh tempo** — `receivables.due_at` selalu ditulis `null`, sehingga laporan umur piutang (`GenerateAgingReceivablesReport`) tidak punya bahan dan semua piutang tampak "belum jatuh tempo" — dan **batas kredit pelanggan** — `customers.credit_limit` hanya tersimpan & ditampilkan, tak pernah diperiksa, sehingga toko tak bisa menegakkan kebijakannya.

## Perubahan

### Konfigurasi
- **`config/pos.php`** — bagian `pos.receivables` baru:
  - `default_due_days` (env `POS_RECEIVABLE_DUE_DAYS`, default 30) — tenggat default penjualan kredit; `0`/negatif berarti tanpa tenggat;
  - `enforce_credit_limit` (env `POS_ENFORCE_CREDIT_LIMIT`, default **false**) — `false` = hanya memperingatkan, `true` = membatasi (menolak).

### Domain
- **`Actions\Sales\CreateSale`** — pada penjualan kredit:
  - **tenggat** dihitung lewat `resolveDueAt()`: `due_at` absolut dari pemanggil menang (dipakai perangkat), lalu `due_days` relatif, lalu default toko; nilai disimpan ke `receivables.due_at` (menggantikan `null` yang lama);
  - **batas kredit** diperiksa lewat `assertCreditLimit()` **sebelum apa pun ditulis**: piutang tertagih dihitung dari baris piutang **terbuka** (sumber tunggal, `withoutGlobalScopes` + filter `tenant_id`), pelanggan tanpa limit (<= 0) selalu lolos, dan hanya ketika `enforce_credit_limit` aktif pelanggaran melempar `CreditLimitExceeded` — sehingga penjualan yang ditolak tidak menyisakan transaksi, item, mutasi stok, piutang, atau audit;
  - payload sinkronisasi kini memuat **`due_at`** sehingga penjualan offline menghasilkan piutang dengan jatuh tempo yang sama di server.
- **`Exceptions\Sales\CreditLimitExceeded`** — pesan Indonesia (nama pelanggan, limit, proyeksi piutang) + respons JSON 422 untuk jalur API.

### UI
- **`resources/views/pages/transactions/create.blade.php`** — computed `creditLimitInfo` (limit/sisa/tertagih/proyeksi) dan banner di panel pembayaran: merah **"Credit limit exceeded"** dengan rincian limit/tertagih/penjualan ini plus penjelasan kebijakan (membatasi vs. hanya memperingatkan), atau info **"Available credit"** saat masih di bawah limit. `confirmSave` menahan kasir lebih awal bila kebijakan membatasi (pesan jelas), dan `save` menangkap `CreditLimitExceeded` sebagai jaring pengaman (keranjang tidak dikosongkan).

### Pengujian
- **`tests/Feature/Sales/CreditLimitAndDueDateTest.php`** (11) — tenggat default/override `due_days`/`due_at` eksplisit/`due_days=0`; bayar penuh tidak membuat piutang; pelanggan tanpa limit tak pernah diblokir; kebijakan membatasi menolak **dan menulis nol** (transaksi, piutang, mutasi stok, audit, saldo, stok); kebijakan memperingatkan tetap mencatat penjualan; penjualan tepat di limit lolos; piutang terbuka yang ada mengurangi sisa kredit; payload outbox perangkat memuat `due_at`.
- **`tests/Feature/Sales/CreditLimitPosUiTest.php`** (4) — perilaku `confirmSave`/`save` pada POS untuk ketiga kebijakan, dan penjualan kredit POS menyimpan jatuh tempo.

## Status suite
- **821 passed / 0 failed / 4 skipped** (2849 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 6 file, 1 perbaikan gaya.
- Utang yang masih terbuka: catatan penagihan (billing notes) FR-07, adaptor payment gateway/QRIS dinamis (menunggu pilihan penyedia), OCR (FR-03), multi-cabang/transfer stok, dokumentasi Fase 6, dan pemeriksaan minor-unit pada data demo (`TransactionSeeder` belum mengisi `*_minor`).

---

# Rebuild POS Universal — Fase 5: Koreksi Pembayaran Piutang Ber-Audit (FR-07, Increment 49) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (FR-07 "Pembatalan atau koreksi pembayaran melalui prosedur yang diaudit", "Setiap pembayaran piutang harus memiliki catatan tersendiri", "Saldo piutang tidak boleh diubah dengan mengedit angka secara langsung", "Koreksi harus dilakukan melalui transaksi koreksi yang memiliki alasan dan jejak audit"; FR-12 katalog izin; §17.4 "Retur dan void").

## Latar
FR-07 sudah punya piutang, pembayaran cicilan, sisa saldo, riwayat, dan daftar jatuh tempo. Satu kewajiban yang belum ada jalannya: **koreksi/pembatalan pembayaran lewat prosedur yang diaudit**. Tanpa itu, pembayaran piutang yang salah (ganda, salah pelanggan, salah nominal) tak bisa diperbaiki sama sekali — dan karena `VoidSale` menolak void selama piutang masih memiliki pembayaran, satu pembayaran keliru bisa mengunci penjualannya selamanya.

## Perubahan

### Skema & model
- **Migrasi `2026_10_13_000003_add_reversal_fields_to_receivable_payments_table.php`** — aditif: `reversed_at`, `reversed_by`, `reversal_reason` (semua nullable) + indeks `reversed_at`.
- **`Models\ReceivablePayment`** — kolom koreksi masuk `$fillable`, cast `reversed_at` datetime, relasi `reversedBy()`, scope **`active()`** (`whereNull('reversed_at')`), dan `isReversed()`. Docblock diperbarui: koreksi menandai baris, bukan menghapus/mengedit nominal.

### Izin & audit
- **`Enums\Permission`** — izin baru `receivable.payment.correct` ("Mengoreksi pembayaran piutang") + label. Karena `DefaultRolesSeeder` (kanonik FR-12) enum-driven, izin otomatis ter-seed; matriks `Enums\Role` memberi Admin dan Supervisor izin ini.
- **`Enums\AuditAction`** — aksi `receivable.payment.corrected`.
- **`Exceptions\Receivables\PaymentReversalNotAllowed`** — alasan wajib, pembayaran tidak ditemukan, piutang sudah void (HTTP 422 dengan pesan Indonesia).

### Aksi domain
- **`Actions\Receivables\ReverseReceivablePayment`** — satu-satunya jalur koreksi; jaminannya di satu tempat:
  - **append-only**: baris tidak dihapus, hanya ditandai `reversed_at`/`reversed_by`/`reversal_reason`;
  - **alasan wajib** dan **izin wajib** (bila aktor diberikan, `ActionAuthorizer` memeriksa `receivable.payment.correct`);
  - **idempoten**: replay koreksi atas pembayaran yang sudah dikoreksi mengembalikan record apa adanya tanpa menambah saldo/audit kedua;
  - **atomik & konsisten**: `remaining_minor` dikembalikan (dibatasi `original_amount_minor`), status piutang dibuka lagi (`closed → open`), cermin `customers.balance` disesuaikan — semua dalam satu transaksi (baris di-`lockForUpdate`);
  - **fail-closed lintas-tenant**: pencarian pembayaran memakai `withoutGlobalScopes()` dengan filter `tenant_id` eksplisit;
  - **jejak audit**: satu baris `receivable.payment.corrected` dengan aktor, perangkat, alasan, dan ringkasan perubahan (amount/remaining/status).

### Integrasi
- **`Actions\Sales\VoidSale`** — penjaga "piutang punya pembayaran" kini menghitung **hanya pembayaran aktif** (`payments()->active()->exists()`). Setelah pembayaran keliru dikoreksi, void penjualan bisa diselesaikan — menutup lingkaran FR-07 ↔ §17.4.

### UI
- **`resources/views/pages/customers/[Customer]/show.blade.php`** — riwayat pembayaran mendapat kolom **Aksi**: tombol **Koreksi** (gated `@can('receivable.payment.correct')`), badge **"Dikoreksi"** + nominal dicoret untuk baris yang sudah dikoreksi. Modal koreksi (juga digated izin) menampilkan ringkasan + field **alasan** wajib. Aksi server memvalidasi ulang izin (`$user->can(...)`) sehingga tak bergantung pada tampilan.

### Pengujian
- **`tests/Feature/Receivables/ReverseReceivablePaymentTest.php`** (9) — pemulihan saldo & pembukaan kembali piutang; append-only + audit; idempoten (satu pemulihan & satu audit); alasan wajib; pembayaran tak dikenal ditolak; izin ditegakkan (viewer ditolak, owner diizinkan); batas tenant; **void diizinkan setelah pembayaran dikoreksi**.
- **`tests/Feature/Customers/ReverseReceivablePaymentUiTest.php`** (5) — owner melihat aksi, viewer tidak; koreksi lewat halaman mengembalikan saldo & menulis audit; alasan wajib; viewer tak bisa mengoreksi meski memanggil aksi.
- **Katalog izin** disesuaikan: `RolePermissionCatalogTest` (11 izin), `DefaultRolesSeederTest` (11 izin) — matriks tetap diverifikasi otomatis terhadap `Enums\Role`.

## Status suite
- **806 passed / 0 failed / 4 skipped** (2816 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 13 file, bersih.
- Utang yang masih terbuka: batas kredit & peringatan pelanggan serta jatuh tempo/catatan penagihan FR-07, adaptor payment gateway/QRIS (menunggu pilihan penyedia), OCR (FR-03), multi-cabang/transfer stok, dokumentasi Fase 6, dan pemeriksaan minor-unit pada data demo (`TransactionSeeder` belum mengisi `*_minor`).

---

# Rebuild POS Universal — Fase 5: Kategori Alert Lokal Lengkap (§11.5, FR-10, Increment 48) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (FR-10 "Daftar alert yang harus ada": permintaan belum tersinkron / sinkronisasi gagal / perangkat belum tersinkron / pembayaran menunggu verifikasi / selisih stok; §11.5 perangkat lama offline; §14 Mode B status `pending`; §17 notifikasi lokal).

## Latar
Increment 47 menutup jalur tarik server→perangkat (§11.3 langkah 9–11). Yang belum lengkap adalah **daftar alert FR-10**: `AlertGenerator` baru menjalankan 4 kategori dari 9 yang disebut plan (stok menipis, data produk belum lengkap, piutang jatuh tempo, backup terlalu lama). Kondisi operasional yang justru paling sering mendahului kegagalan — sinkronisasi menumpuk, operasi ditolak server, perangkat tidak menyapa, pembayaran menggantung, dan selisih stok hasil opname — belum memunculkan notifikasi apa pun, sehingga pengguna tidak punya cara sadar sebelum datanya berbeda dari server.

## Perubahan

### Kategori baru
- **`Alert`** — 5 konstanta jenis baru: `sync.backlog`, `sync.failed`, `device.stale`, `payment.awaiting_verification`, `stock.discrepancy` (semuanya aditif; `device_id` sudah ada di skema sejak awal).
- **`Support\Alerts\AlertGenerator`** — 5 metode baru, semuanya masuk ke `run()`:
  - **`syncBacklog()`** (§11.5) — operasi `pending` yang melewati `pos.alerts.sync_backlog_minutes` (default 30), **dikelompokkan per perangkat** (satu alert, payload `pending_count` + waktu tertua); perangkat tanpa `device_id` dilewati.
  - **`syncFailed()`** — operasi berstatus `failed` (ditolak server), dikelompokkan per perangkat, keparahan `critical` (butuh tindakan pengguna, bukan sekadar menunggu).
  - **`staleDevices()`** — perangkat aktif (belum dicabut) yang tidak menyapa server melewati `pos.alerts.device_stale_hours` (default 24); **perangkat yang baru dibuat dikecualikan** agar pendaftaran baru tidak langsung memicu noise.
  - **`paymentsAwaitingVerification()`** (FR-05, §14) — transaksi `awaiting_verification` yang belum dibatalkan dan melewati `pos.alerts.payment_verification_minutes` (default 30), keparahan `critical`.
  - **`stockDiscrepancies()`** (FR-08) — opname dengan `difference != 0` dalam `pos.alerts.stock_discrepancy_days` (default 7), satu alert per produk (baris terbaru menang). `stock_opnames` tidak punya `tenant_id`, jadi kepemilikan disaring lewat produk.
- **`createIfNew()`** — parameter opsional ke-6 `?string $deviceId = null`; alert berbasis perangkat mengisi `alerts.device_id` dan memakai `deduplication_key` ber-prefix id perangkat (`sync.backlog:{id}`, dst.) sehingga dedup tetap benar per perangkat.
- **Fail-closed:** kategori baru memakai `resolveTenantId()` (tenant eksplisit **atau** konteks request); bila keduanya kosong, generator mengembalikan 0 dan tidak pernah membaca lintas-tenant.
- **`config/pos.php`** — bagian `pos.alerts` baru (`sync_backlog_minutes`, `device_stale_hours`, `payment_verification_minutes`, `stock_discrepancy_days`, semua dapat di-override lewat env) supaya ambang tidak tersebar sebagai angka ajaib.
- **`Console\Commands\GenerateAlerts`** — deskripsi diperbarui menyebut seluruh kategori.

### UI
- **`resources/views/pages/alerts/index.blade.php`** — filter jenis memuat 9 jenis, `$typeLabel()` + `$describe()` memberi label/kalimat Indonesia untuk tiap kategori baru (perangkat, jumlah operasi, invoice, selisih stok), dan subjudul menyebut sinkronisasi & pembayaran.

### Pengujian
- **`tests/Feature/Alerts/SyncAndOperationalAlertsTest.php`** (13) — backlog dibuat untuk operasi lama dan **tidak** untuk yang baru/tersinkron, dedup sampai dibaca; gagal dikelompokkan per perangkat; perangkat basi dibuat, dan dikecualikan untuk perangkat baru/baru menyapa/dicabut; pembayaran menunggu verifikasi dibuat, dan diabaikan untuk yang baru/terverifikasi/void; selisih stok per produk, mengabaikan stok cocok & produk tenant lain; backlog tidak bocor antar-tenant; kategori baru fail-closed tanpa tenant; `run()` menjumlahkan kategori baru.

## Status suite
- **792 passed / 0 failed / 4 skipped** (2775 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 6 file, bersih.
- Utang yang masih terbuka: adaptor payment gateway/QRIS (menunggu pilihan penyedia), OCR (FR-03), multi-cabang/transfer stok, dokumentasi Fase 6, pemeriksaan minor-unit pada data demo (`TransactionSeeder` belum mengisi `*_minor`), dan kategori alert kesembilan plan (aplikasi butuh update/migrasi DB) sengaja ditunda ke native updater Fase 6.

---

# Rebuild POS Universal — Fase 5: Tarik Perubahan Server ke Perangkat (§11.1, §11.3 langkah 9–11, §11.5, Increment 47) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§11.1 "Perangkat melacak perubahan yang sudah dikirim dan dikonfirmasi", "Sinkronisasi harus aman terhadap pengiriman ulang"; §11.3 langkah 9 "Perangkat menarik perubahan server sejak cursor terakhir" & langkah 10 "Perangkat menerapkan perubahan", langkah 11 "Konflik dicatat dan ditampilkan kepada pengguna yang berwenang"; §11.5 perangkat lama offline; §12 tenant dari identitas perangkat; §17.1 identitas sinkronisasi; §18 `GET /api/v1/sync/pull?cursor=...`; Sprint 4 item 3 "API push/pull").

## Latar
Sisi server penarikan perubahan sudah lengkap sejak Sprint 4 item 4 (jurnal `sync_changes`, kursor monoton, endpoint `GET /api/v1/sync/pull`, pengujian paginasi & isolasi tenant). Yang belum ada adalah **sisi perangkat**: `SyncTransport` hanya punya `push()`, sehingga urutan §11.3 berhenti di langkah 8. Tanpa langkah 9–10, perangkat yang membuat penjualan/retur offline tidak pernah menerima perubahan pihak lain — katalog, harga, dan pelanggan yang diubah di server (atau perangkat lain) tidak pernah sampai, dan konflik antar-perangkat hanya bisa diketahui server, bukan ditampilkan di tempat kerja yang menghadapinya.

## Perubahan

### Transport
- **`SyncTransport::pull(string $token, ?string $cursor, int $limit): SyncChangeBatch`** — kontrak baru untuk penarikan (§11.3 langkah 9); hasil tak diketahui (koneksi/timeout/5xx) tetap `SyncTransportException` sehingga perangkat mengulang dari kursor lama.
- **`HttpSyncTransport::pull()`** — `GET {server}/api/v1/sync/pull?cursor=&limit=` dengan token perangkat, menerjemahkan envelope server (`cursor`/`has_more`/`changes`) menjadi `SyncChangeBatch`.

### Penerapan perubahan lokal
- **`Support\Sync\DatabaseServerChangeApplier`** (baru) — menerapkan satu perubahan jurnal pada basis data lokal (§11.3 langkah 10) dengan aturan:
  - **tidak memantulkan perubahan sendiri** (§11.1): perubahan yang `device_id`-nya perangkat ini dilewati;
  - **hanya data acuan yang dicerminkan** — produk, kategori, pelanggan, pemasok, promosi, menu, bahan. Dokumen transaksi/pesanan/pembelian/mutasi stok **tidak** ditimpa dari server, karena itu pekerjaan perangkat masing-masing (§11.4 "transaksi perangkat tidak dihapus diam-diam"); jenis entitas lain dilewati tanpa menghapus data (§11.5);
  - **idempoten** (§11.1): data lokal yang sudah sama dilewati, dan `updated_at` lokal disetel ke waktu perubahan server sehingga penerapan ulang tidak salah dibaca sebagai suntingan lokal yang lebih baru;
  - **tidak menimpa suntingan lokal yang lebih baru** (§11.3 langkah 11): perubahan server yang lebih tua daripada perubahan lokal tidak diterapkan, dan sebuah kasus konflik (`data`) dicatat — idempoten per `(operation_id, type)`;
  - **tanpa penjurnalan ulang** (§11.1): penerapan dibungkus `SyncChangeJournal::withoutRecording()` supaya perubahan yang ditarik tidak dicatat sebagai perubahan baru dan tidak memantul antar-perangkat tanpa henti.
- **`Support\Sync\SyncChangeJournal::withoutRecording()`** — mode penjurnalan yang ditangguhkan (dipulihkan otomatis lewat `finally`, aman bersarang).

### Orkestrasi & kursor
- **`Actions\Sync\PullServerChanges`** (baru) — satu putaran penarikan: menarik halaman berurutan sampai `has_more` false atau batas `pos.sync.max_pull_pages` (default 20) tercapai (§11.5 perangkat lama offline menyusul bertahap), menerapkan setiap perubahan lewat applier, dan menyimpan kursor pada `devices.last_sync_cursor` **hanya setelah halaman itu diterapkan** — kegagalan transport tidak pernah melompati data. Token perangkat, keberadaan perangkat, dan status cabut (§15) divalidasi seperti pada jalur push.
- **`Actions\Sync\SyncNow`** — kini satu putaran penuh §11.3: kirim outbox (langkah 3–8) lalu tarik & terapkan perubahan server (langkah 9–10) secara default. Kegagalan penarikan **tidak** membatalkan pengiriman yang sudah dikonfirmasi: pesannya dikembalikan sebagai `pullError` dan putaran berikutnya melanjutkan dari kursor yang sama.
- **`SyncPushSummary`** — membawa `pulled` (`SyncPullSummary`: applied/skipped/conflicted/pages/cursor/has_more) dan `pullError`; `toArray()` ikut memuatnya.
- **`config/pos.php`** — `pos.sync.max_pull_pages` (env `POS_SYNC_MAX_PULL_PAGES`, default 20).
- **`DatabaseSyncChangeFeed`** — payload perubahan kini menyertakan `device_id` (agar perangkat dapat mengenali perubahannya sendiri).
- **`Enums\ServerChangeOutcome`** (baru: `applied`/`skipped`/`conflicted`) dan **`SyncConflictType::Data`** ("Konflik data") untuk konflik suntingan lokal vs perubahan server.
- **UI `/sync`** — tombol "Sinkronkan sekarang" kini juga menampilkan hasil penarikan (diterapkan/dilewati/konflik) dan memberi peringatan eksplisit bila penarikan gagal sementara pengiriman berhasil.

### Pengujian
- **`tests/Feature/Sync/PullSyncTest.php`** (16) — produk baru dari server dibuat lokal (identitas §17.1 & tenant §12 dihormati, kursor tersimpan, **tidak** menulis jurnal baru); perubahan lebih baru diterapkan; penerapan ulang tanpa efek (dan `updated_at` tidak bergeser); perubahan milik perangkat sendiri dilewati; jenis entitas tak dicerminkan dilewati tanpa kehilangan data; perubahan server yang lebih tua tidak menimpa suntingan lokal dan menghasilkan kasus konflik `data` (tidak tergandakan saat diulang); penghapusan dari server menghapus baris lokal, penghapusan tanpa baris lokal dilewati; paginasi sampai habis; batas halaman membatasi satu putaran **dan** menyimpan kemajuan (putaran berikutnya menyusul); kegagalan transport tidak menggeser kursor; token wajib; perangkat dicabut ditolak; `SyncNow` push-lalu-pull dalam satu putaran; kegagalan penarikan tidak membatalkan pengiriman dan dilaporkan sebagai `pullError`.
- **`FakeSyncTransport`/`LoopbackSyncTransport`** — mendukung `pull()` (loopback menembus endpoint nyata, termasuk autentikasi perangkat & kursor).

## Status suite
- **779 passed / 0 failed / 4 skipped** (2729 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 42 file dipindai, 1 issue diperbaiki, sisanya bersih.
- Utang yang masih terbuka: adaptor payment gateway/QRIS (menunggu pilihan penyedia), OCR (FR-03), multi-cabang/transfer stok, dokumentasi Fase 6, dan pemeriksaan minor-unit pada data demo (`TransactionSeeder` belum mengisi `*_minor`).

---

# Rebuild POS Universal — Fase 5: Retur Penjualan dari Perangkat (§11.1–§11.3, §17.1, §17.4, Increment 46) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§5.1 MVP #15 "Status offline/online dan antrean sinkronisasi"; §11.1 "Operasi lokal harus dapat diselesaikan tanpa menunggu server", "Sinkronisasi harus aman terhadap pengiriman ulang"; §11.2 struktur outbox; §11.3 langkah 4–7 & "urutan operasi"; §11.5 operasi lama tidak dibuang; §12 tenant dari perangkat; §17.1 identitas sinkronisasi; §17.4 retur; FR-08).

## Latar
Increment 45 selesai di sisi domain & web, tetapi menutup satu celah yang **dibuat oleh increment itu sendiri**: `CreateSalesReturn` sudah memasukkan operasi `sale_return`/`create` ke outbox perangkat (§11.1), sedangkan `SyncOperationApplier` hanya punya handler untuk entitas `sale`. Akibatnya retur yang dibuat di perangkat dikirim ke server lalu **selalu** berakhir `received` (deferred) — data tersimpan durable tetapi tidak pernah diterapkan. Padahal §17.4 menuntut retur berjalan juga saat perangkat offline, dan FR-08 adalah fitur inti, bukan pelengkap.

Sekaligus ditemukan dua kekurangan pada payload Increment 45 yang membuat penerapan mustahil: payload tidak membawa `user_id` (audit & kepemilikan dokumen), dan baris dirujuk hanya lewat `transaction_item_id` — id yang **ditetapkan server**, sehingga perangkat yang belum pernah menariknya (langkah tarik §11.3 langkah 9–10 belum ada) tidak punya cara menyebut baris mana yang diretur.

## Perubahan

### Handler penerapan
- **`Support\Sync\Handlers\SaleReturnSyncHandler`** (baru) — menerapkan operasi `sale_return`/`create` (§11.3 langkah 6), terdaftar di `SyncOperationApplier` (`AppServiceProvider`) bersama `SaleSyncHandler`:
  - **validasi urutan** (§11.3 langkah 5): retur hanya sah bila penjualannya sudah ada di server — perangkat yang meretur penjualan yang belum terkirim ditolak dengan alasan jelas (dalam satu batch, urutan `local_sequence`/id inbox menjamin create diterapkan lebih dahulu);
  - **identitas diadopsi** (§17.1): `operation_id` perangkat menjadi `client_request_id`, dan `entity_id` (uuid dokumen retur milik perangkat) diadopsi server — operasi lanjutan yang menunjuk retur itu tetap sah; `entity_id` yang bukan uuid ditolak;
  - **tenant & perangkat selalu dari server** (§12), tidak dari payload;
  - **penerapan ulang idempoten** (§11.1): dokumen dengan identitas itu sudah ada → `applied` tanpa efek kedua; `operation_id` unik di `sync_inbox` + status terminal + `client_request_id` pada `CreateSalesReturn` membuat **stok tidak dikembalikan dua kali**;
  - **resolusi baris berlapis** — `transaction_item_uuid` → `transaction_item_id` → `product_id` (+ `product_unit_id`) dengan memilih baris yang sisanya paling banyak dan belum dipakai baris lain dalam payload yang sama; baris yang tak dapat dicocokkan ditolak, bukan diterapkan sebagian;
  - pelanggaran domain (`ReturnNotAllowed`) → `rejected` dengan pesan domain; kegagalan lain → `rejected` (tidak melempar, sesuai kontrak `SyncOperationHandler`);
  - operasi perangkat **tidak dipantulkan** kembali ke outbox server (`enqueue_for_sync: false`, §11.1).
- **`Actions\Sales\CreateSalesReturn`** — payload outbox diperkaya: `user_id`, serta per baris `transaction_item_uuid` + `product_id` + `product_unit_id` + `reason` (id server tetap disertakan). Kunci `transaction_id`/`transaction_uuid` kini dipisah dari `uuid`, dan `uuid` dipakai sebagai **identitas dokumen retur** yang diadopsi server (`uuid` di luar `$fillable`, di-set sebelum simpan — konvensi model).

### Pengujian
- **`tests/Feature/Sync/SaleReturnSyncTest.php`** (14) — retur perangkat diterapkan sebagai dokumen domain (identitas diadopsi, mutasi `sale_return`, audit dengan perangkat, inbox `applied`); baris dirujuk lewat uuid; lewat **produk** (perangkat tanpa id server); create + retur dalam **satu batch** diterapkan berurutan; pengiriman ulang tidak mengembalikan stok dua kali; operasi berbeda dengan identitas retur sama tidak menggandakan; tanpa alasan / melebihi nota / penjualan belum ada di server / baris tidak cocok / identitas bukan uuid → `rejected`; retur kredit perangkat mengoreksi piutang lebih dahulu lalu refund sisanya; retur di perangkat menulis tepat satu operasi outbox (dengan referensi baris lengkap); penerapan tidak memantulkan operasi ke outbox.

## Status suite
- **763 passed / 0 failed / 4 skipped** (2661 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 4 file, 0 issue tersisa.
- Celah lanjutan yang diketahui: langkah tarik §11.3 langkah 9–10 (`SyncTransport::pull()`) belum ada, sehingga perangkat belum menerima perubahan server setelah cursor terakhir — berikutnya setelah retur & void perangkat tersambung penuh.

---

# Rebuild POS Universal — Fase 5: Retur Penjualan Terkontrol (§17.4, FR-08, FR-09, Increment 45) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (FR-08 "Retur penjualan dengan alasan"; FR-09 "Retur dan pembatalan"; §17.4 "Retur dan void" — "Pengembalian stok hanya dilakukan sesuai kebijakan retur", "Koreksi pembayaran harus tercatat"; §17.1 idempotensi; FR-12 izin & audit; FR-07 piutang).

## Latar
Increment 43 menutup pembatalan (void); §17.4 menuntut pasangan keduanya, **retur**, dan FR-08 menyebut "Retur penjualan dengan alasan" sebagai fitur stok inti. Halaman `/returns/sales` sudah ada di sidebar dan **tampak berfungsi**, padahal rusak dan melanggar aturan: ia membaca `transaction_items.qty` & `price` (kolom yang sebenarnya bernama `quantity` & `unit_price`) sehingga batas jumlah selalu 0, menulis `sales_returns.total` desimal tanpa tenant/uuid, **menambah stok sendiri** dengan `increment('stock', …)` (melewati ledger, melanggar "setiap perubahan stok harus berasal dari dokumen mutasi"), dan tidak memeriksa izin maupun alasan. Tidak ada satu pun tes yang menyentuhnya.

## Perubahan

### Migrasi
- **`2026_10_13_000002_add_identity_and_money_to_sales_returns_tables.php`** (aditif):
  - `sales_returns` — `uuid` (unik), `tenant_id` (uuid, indeks), `version`, `deleted_at`, `subtotal_minor`/`discount_minor`/`total_minor`, `receivable_adjustment_minor`, `refund_minor`, `stock_restored`, `location_id`, `device_id`, `client_request_id` (unik) — plus `customer_id` dibuat **nullable** agar retur penjualan tunai tanpa pelanggan (walk-in) mungkin;
  - `sales_return_items` — `uuid` (unik), `tenant_id`, `unit_price_minor`, `discount_minor`, `subtotal_minor`, `version`, `deleted_at`;
  - **backfill** dari cermin desimal lama: IDR berminor exponent 0, jadi minor = nilai rupiah bulat — **tanpa** ×100 (konvensi yang sama dengan backfill tabel penjualan `2026_10_10_000009`); kolom desimal lama (`total`/`price`/`subtotal`) dipertahankan sebagai cermin transisional, ditulis oleh aksi domain.

### Aksi Domain
- **`Sales\CreateSalesReturn`** (baru) — satu-satunya jalur retur, atomik (`DB::transaction` + `lockForUpdate`):
  - **alasan wajib** (`ReturnNotAllowed::reasonRequired`) dan **izin wajib** (`transaction.return`, FR-12);
  - **jumlah tidak melebihi nota**: per baris dibatasi `quantity − Σ qty retur sebelumnya`, sehingga retur berulang tidak dapat melebihi penjualan;
  - **nilai mengikuti nota**, bukan harga katalog kini — nilai baris dihitung proporsional dari `transaction_items.subtotal_minor` (sudah memperhitungkan diskon baris), dengan snapshot `unit_price_minor` per baris;
  - **stok sesuai kebijakan** (`pos.sales.restore_stock_on_return`, override per panggilan): mutasi `sale_return` (+qty, `before_stock`/`after_stock` benar, `saveQuietly()` seperti `CreateSale`), memakai faktor konversi satuan jual; produk stok tak terbatas tidak membuat mutasi;
  - **koreksi pembayaran tercatat (§17.4/FR-07)**: nilai retur lebih dahulu membatalkan sisa piutang (`receivable_adjustment_minor`, mengurangi `remaining_minor` **dan** `original_amount_minor` agar invarian `original = dibayar + sisa` tetap berlaku; piutang menjadi `closed` bila sisa 0; saldo pelanggan dikoreksi), sisanya menjadi `refund_minor` (kas keluar);
  - **transaksi batal tidak dapat diretur** (`ReturnNotAllowed::voidedSale`) — koreksinya sudah void;
  - **idempoten** (`client_request_id` unik): pengiriman ulang mengembalikan dokumen yang sama, sehingga **stok tidak dikembalikan dua kali** (§17.1);
  - **audit** `AuditAction::SaleReturned` (`sale.returned`) dengan aktor, perangkat, alasan, nilai, koreksi piutang, refund, dan ringkasan baris (FR-12);
  - **outbox perangkat** (§11.1): bila retur dibuat di perangkat (`device_id`), satu operasi `sale_return`/`create` masuk outbox dalam transaksi yang sama; dapat dimatikan (`enqueue_for_sync: false`).
- **`App\Exceptions\Sales\ReturnNotAllowed`** (baru) — pelanggaran domain retur (alasan kosong, transaksi tak ditemukan, transaksi batal, baris bukan milik transaksi, jumlah melebihi sisa) dengan `render()` 422.

### Model, Enum & Konfigurasi
- **`SalesReturn`/`SalesReturnItem`** dibangun ulang mengikuti konvensi rebuild (`BelongsToTenant`, `HasSyncIdentity`, `JournalSyncChanges`, `SoftDeletes`, cast integer minor, `version`), relasi `items()`/`transaction()`/`productUnit()`, nomor `RTS-…` otomatis; accessor nilai memakai **`Money`** (menghormati `pos.currency.minor_exponent`), bukan pembagian 100 tetap.
- **`Transaction`** — relasi `receivable()` dan helper `isCredit()`.
- **`Permission::ReturnTransaction`** = `'transaction.return'` (label "Melakukan retur penjualan"), ditambahkan ke matriks `Role` owner/admin/supervisor/**cashier** (default yang dapat disesuaikan; mengikuti preseden `transaction.void`).
- **`AuditAction::SaleReturned`** = `'sale.returned'`.
- **`config/pos.php`** — `sales.restore_stock_on_return` (default `true`, `POS_RESTORE_STOCK_ON_RETURN`).

### UI
- **`/returns/sales`** ditulis ulang: halaman tipis yang **tidak pernah menulis stok atau angka uang sendiri** — semuanya lewat `CreateSalesReturn`. Daftar retur menampilkan nota, pelanggan, nilai retur, koreksi piutang, dan refund; pencarian nota menolak transaksi batal, menampilkan **terjual/sudah diretur/maksimum** per baris, nilai per unit menurut nota, perkiraan refund kas, alasan retur wajib, dan saklar kebijakan pengembalian stok. Aksi hanya ditawarkan kepada pemegang izin `transaction.return` (`@can`), sedangkan penegakannya tetap di domain.
- **`/reports`** — kartu **"Retur Penjualan"** (jumlah, nilai, refund kas) di samping kartu "Transaksi Dibatalkan" (FR-09 "Retur dan pembatalan").

### Laporan
- **`Reports\SalesReport`** — metrik `retur_count`, `retur_minor`, `retur_refund_minor` beserta definisinya; retur **tidak** mengurangi omzet (FR-09: omzet ≠ penerimaan ≠ retur).
- **`Actions\Reports\GenerateCashFlowReport`** — retur penjualan menjadi **arus kas keluar** (`retur_penjualan`) sebesar `refund_minor` saja; koreksi yang hanya membatalkan piutang tidak menggerakkan kas. Placeholder "Sales returns would need a separate action" dihapus.

### Data demo
- **`database/seeders/ReturnSeeder.php`** — retur penjualan demo ditulis dengan bentuk baru (minor, tenant, uuid, cermin desimal) dan jatuh kembali ke cermin desimal untuk transaksi demo lama yang `subtotal_minor`-nya masih 0; stok sengaja tidak diubah (jalur otoritatif tetap `CreateSalesReturn`).

### Pengujian
- **`tests/Feature/Sales/SalesReturnTest.php`** (20) — dokumen retur + mutasi `sale_return` + audit; nilai proporsional untuk retur sebagian; alasan wajib; izin (`viewer` ditolak); jumlah melebihi nota ditolak; retur berulang dibatasi sisa; baris milik transaksi lain ditolak; transaksi batal tidak dapat diretur; transaksi tak dikenal / tanpa item ditolak; `client_request_id` sama → satu dokumen & stok kembali sekali; kebijakan stok mati (dan override eksplisit); produk stok tak terbatas tanpa mutasi; retur kredit membatalkan piutang lebih dahulu (refund hanya sisanya); piutang sudah lunas → refund penuh; laporan memisahkan retur dari omzet & arus kas; retur di perangkat menulis tepat satu operasi outbox.
- **`tests/Feature/Sales/SalesReturnUiTest.php`** (5) — retur dari halaman; sisa jumlah tampil setelah retur pertama; alasan kosong tidak mengubah apa pun; aktor tanpa izin tidak ditawari aksinya (dan panggilan langsung ditolak domain); nota batal tidak dapat dipilih.
- **`tests/Feature/ReportsTest.php`** — retur tampil sebagai metrik tersendiri di `/reports`.
- **`tests/Feature/Users/RolePermissionCatalogTest.php`**, **`DefaultRolesSeederTest.php`** — katalog izin 10, matriks supervisor/cashier bertambah `transaction.return`.

## Status suite
- **749 passed / 0 failed / 4 skipped** (2553 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint: 20 file, 0 issue tersisa.
- Migrasi `2026_10_13_000002` dijalankan di dev (DDEV/MySQL); `ReturnSeeder` dijalankan ulang agar data demo konsisten (desimal & minor sepakat).
- Catatan: data demo penjualan lama (`TransactionSeeder`) masih hanya mengisi cermin desimal sehingga `transactions.*_minor` bernilai 0 pada baris demo — bukan bagian increment ini, tetapi perlu satu increment "minor units data demo" agar laporan demo tidak menampilkan 0.

---

# Rebuild POS Universal — Fase 5: Pembatalan dari Perangkat & Identitas Penjualan (§11.1–§11.3, §17.1, Increment 44) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§5.1 MVP #15 "Status offline/online dan antrean sinkronisasi"; §11.1 "Operasi lokal tidak boleh menunggu server", "aman terhadap pengiriman ulang"; §11.2 struktur outbox; §11.3 langkah 3–7; §17.1 identitas sinkronisasi; §17.4 pembatalan idempoten; §12 tenant dari perangkat).

## Latar
Increment 43 menyediakan void dari sisi web. Namun §17.4 juga menuntut pembatalan berjalan saat perangkat **offline**: perangkat yang membatalkan penjualan tanpa jaringan harus dapat mengirim pembatalan itu, dan penerapannya di server harus idempoten agar stok tidak dikembalikan dua kali. Sebelum ini `SaleSyncHandler` hanya menerima `sale`/`create`, sehingga operasi pembatalan perangkat tidak dapat diterapkan sama sekali. Sekaligus ditemukan celah §17.1: penjualan yang dikirim perangkat **tidak** memakai uuid perangkat, sehingga identitas penjualan berbeda antara server dan perangkat (operasi lanjutan yang menunjuk `entity_id` perangkat selalu gagal).

## Perubahan

### Identitas penjualan dari perangkat (§17.1/§11.1)
- **`Sales\CreateSale`** — menerima `uuid` opsional (aditif): bila ada, uuid lokal perangkat dipakai apa adanya sebagai identitas penjualan di server, sehingga server dan perangkat memiliki identitas yang sama. Divalidasi (`Str::isUuid`), di-set langsung pada model sebelum `save()` (di luar `$fillable`, satu baris jurnal `created` — tidak menghasilkan record kembar di perangkat). Tanpa `uuid`, perilaku lama (uuid7 server) tidak berubah.

### Pembatalan dari perangkat (§17.4 + §11.3)
- **`Support\Sync\Handlers\SaleSyncHandler`** — kini mendukung dua operasi:
  - `sale`/`create` — seperti sebelumnya, ditambah adopsi `entity_id` perangkat sebagai uuid penjualan dan idempotensi berbasis identitas (operasi dengan `entity_id` yang sudah ada mengembalikan record yang sama, bukan penjualan kedua);
  - `sale`/`update` — **void** lewat `VoidSale`: payload wajib menyatakan void (`void: true` atau `payment_status: 'void'`) dan memuat `user_id` + `reason`; tenant/perangkat selalu diambil dari server (§12); `enqueue_for_sync: false` supaya operasi perangkat tidak dipantulkan kembali (§11.1).
  - Validasi urutan (§11.3 langkah 5): void untuk penjualan yang belum ada di server **ditolak** dengan pesan jelas — penjualannya harus dikirim lebih dahulu (satu batch tetap aman karena inbox diterapkan urut `id`/`local_sequence`, sehingga create mendahului update).
  - Pelanggaran domain (`VoidNotAllowed`: tanpa alasan, piutang sudah dibayar) → `rejected`, bukan `conflict`.
- **`Sales\VoidSale`** — menambah `client_request_id` & `enqueue_for_sync` (aditif) dan langkah outbox: bila pembatalan berjalan di perangkat (`device_id` ada), satu operasi `sale`/`update` dengan payload `{ void: true, user_id, reason, restore_stock }` dimasukkan ke outbox perangkat **di dalam transaksi yang sama** (§11.1 transactional outbox). `client_request_id` juga direkam di metadata audit `sale.voided` agar penerapan ulang perangkat dapat dilacak.

### Pengujian
- **`tests/Feature/Sync/SaleVoidSyncTest.php`** (13) — void perangkat diterapkan ke domain (stok kembali, mutasi `sale_void`, audit dengan `device_id` + `client_request_id`, item tetap ada); kirim ulang void tidak mengembalikan stok dua kali; penerapan ulang (tanpa baris `received`) tetap idempoten; server mengadopsi uuid penjualan dari perangkat; identitas penjualan yang sama tidak pernah dibuat dua kali; `entity_id` bukan UUID ditolak; create+void dalam satu batch diterapkan berurutan; void tanpa alasan ditolak; `update` tanpa pernyataan void ditolak; void atas penjualan yang belum ada di server ditolak (urutan); void atas piutang yang sudah dibayar ditolak; void di perangkat menulis tepat satu operasi outbox `update`; penerapan void tidak memantulkan operasi kembali ke outbox.

## Status suite
- **723 passed / 0 failed / 4 skipped** (2431 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint 4 file, 1 style issue.
- Tidak ada migrasi baru pada increment ini.

---

# Rebuild POS Universal — Fase 5: Pembatalan Transaksi Terkontrol / Void (§17.4, Increment 43) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§5.1 MVP #10 "Retur & void"; §17.4 "Retur dan void": "Transaksi selesai tidak dihapus permanen", "Pembatalan dan retur harus memerlukan izin dan alasan", "Pengembalian stok hanya dilakukan sesuai kebijakan retur"; FR-12 audit; FR-07 piutang; FR-08 stok).

## Perubahan

### Migrasi
- **`2026_10_13_000001_add_void_fields_to_transactions_table.php`** — kolom aditif & nullable pada `transactions`: `voided_at`, `voided_by` (FK `users`, nullOnDelete), `void_reason` (500), `stock_restored` (bool, default false), plus indeks `payment_status` (laporan kini menyaring transaksi batal pada setiap periode). Sebelumnya pembatalan dilakukan dengan **hapus permanen**: `items()->delete()` + soft-delete transaksi, tanpa izin, alasan, pengembalian stok, maupun jejak audit — melanggar §17.4.

### Aksi Domain
- **`Sales\VoidSale`** (baru) — satu-satunya jalur pembatalan, atomik (`DB::transaction` + `lockForUpdate`):
  - **tidak menghapus**: transaksi ditandai `payment_status = 'void'` (konstanta `Transaction::PAYMENT_STATUS_VOID`) dan itemnya tetap utuh;
  - **alasan wajib** (`VoidNotAllowed::reasonRequired`) dan **izin wajib** (`transaction.void` lewat `ActionAuthorizer`, FR-12);
  - **idempoten**: pemanggilan ulang mengembalikan `already_void: true` tanpa mengembalikan stok lagi dan tanpa mutasi/audit ganda;
  - **mutasi balik dari ledger**, bukan hitung ulang item: kueri `stock_movements` `reference_type='sale'`/`reference_id` → tulis mutasi `sale_void` dengan `quantity_change` positif dan `before_stock`/`after_stock` yang benar, serta `Product::saveQuietly()` (pola `CreateSale`, agar observer tidak menggandakan mutasi). Dapat dimatikan per panggilan (`restore_stock`) atau lewat kebijakan;
  - **piutang dijaga (FR-07)**: piutang tanpa pembayaran → `status = 'void'`, `remaining_minor = 0`, saldo pelanggan dikoreksi kembali (`decrement('balance', …)`); bila sudah ada `receivable_payments`, void **ditolak** (`VoidNotAllowed::receivableHasPayments`) supaya pembayaran tidak tersembunyi;
  - **audit** `AuditAction::SaleVoided` (`sale.voided`) dengan aktor, perangkat, alasan, status pembayaran sebelumnya, dan ringkasan perubahan (FR-12).
- **`App\Exceptions\Sales\VoidNotAllowed`** (baru) — pelanggaran domain void (alasan kosong, transaksi tak ditemukan, piutang sudah dibayar) dengan `render()` 422.

### Model, Enum & Konfigurasi
- **`Transaction`** — `voided_at`/`voided_by`/`void_reason`/`stock_restored` masuk `$fillable` + cast (`datetime`/`integer`/`boolean`), konstanta status pembayaran, helper `isVoided()`, relasi `voidedBy()`.
- **`AuditAction::SaleVoided`** = `'sale.voided'`.
- **`config/pos.php`** — `sales.restore_stock_on_void` (default `true`, `POS_RESTORE_STOCK_ON_VOID`): §17.4 "Pengembalian stok hanya dilakukan sesuai kebijakan retur".

### Laporan (void dikecualikan)
- **`Reports\SalesReport`** — omzet, penerimaan, piutang baru, diskon item, dan produk terlaris tidak lagi menghitung transaksi batal; ditambahkan metrik `dibatalkan_count` dan `dibatalkan_minor` beserta definisinya (FR-09), serta `voided_at`/`void_reason` pada `riwayat` supaya pembatalan tetap terlihat.
- **`/dashboard`** — omzet hari ini, jumlah transaksi hari ini, tren 7 hari, dan produk terlaris menyaring transaksi batal; daftar "Transaksi Terbaru" menandainya **Dibatalkan** dengan nominal dicoret.
- **`/reports`** — ringkasan, tren harian, komposisi metode, produk terlaris, kategori, dan laba menyaring transaksi batal; kartu **"Transaksi Dibatalkan"** ditambahkan sebagai laporan terpisah (tidak disembunyikan).
- **`GenerateCashFlowReport`** & **`GenerateMarginHistoryReport`** sudah menyaring batal; tak perlu diubah.

### UI (Folio/Volt)
- **`/transactions`** — aksi hapus permanen **diganti** tombol **"Batalkan"** (menu `x-circle`, hanya pemegang `transaction.void`, hanya untuk transaksi aktif) dengan modal alasan wajib + peringatan bahwa transaksi batal tidak dapat diaktifkan kembali; baris batal bergaris coret dengan badge merah **Dibatalkan**; aksi Edit disembunyikan untuk transaksi batal; modal detail menampilkan waktu & alasan pembatalan. `VoidNotAllowed`/`ActionForbidden` ditampilkan sebagai toast, bukan error 500.
- **`/transactions/[Transaction]`** — transaksi batal bersifat final: `confirmSave`/`save` ditolak, tombol "Review & Update" dinonaktifkan, dan banner merah menampilkan alasan + waktu pembatalan.
- **`/transactions/[Transaction]/receipt`** — struk transaksi batal dicetak dengan blok **\*\*\* DIBATALKAN \*\*\*** (alasan + waktu) agar tidak tampak seperti penjualan sah.

### Pengujian
- **`tests/Feature/Sales/VoidSaleTest.php`** (9) — void menandai + mengembalikan stok lewat mutasi `sale_void` + audit lengkap; item & baris tidak terhapus; alasan wajib; aktor tanpa izin ditolak (`ActionForbidden`); idempoten (stok/Mutasi/audit tidak ganda); kebijakan `restore_stock_on_void=false` tidak menyentuh stok; flag eksplisit mengalahkan kebijakan; transaksi tak dikenal ditolak; penjualan kredit belum dibayar → piutang & saldo pelanggan dikoreksi; piutang **sudah** dibayar → void ditolak dan tidak ada perubahan; laporan keuangan bersih dari transaksi batal (`dibatalkan_count`/`dibatalkan_minor`/`riwayat`).
- **`tests/Feature/Sales/VoidSaleUiTest.php`** (4) — void dari daftar dengan alasan; tanpa alasan tidak mengubah apa pun; aktor tanpa izin tidak ditawari aksi (dan pemanggilan langsung tetap ditolak); transaksi batal ditandai di daftar dan tidak dapat disimpan dari halaman edit.

## Status suite
- **710 passed / 0 failed / 4 skipped** (2353 assertions) — dikonfirmasi 2× (sebelum & sesudah Pint). Pint 14 file, 4 style issues.
- Migrasi `2026_10_13_000001` dijalankan di dev.

---

# Rebuild POS Universal — Fase 5: Pembayaran Campuran / Split Payment (FR-05, Increment 42) (belum dirilis)

**Tanggal:** 13 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§5.1 MVP #7; FR-05 Pembayaran: "Pembayaran campuran"; FR-07 penjualan kredit; §17.1 uang integer minor).

## Perubahan

### Migrasi
- **`2026_10_12_000006_create_transaction_payments_table.php`**: tabel `transaction_payments` — satu baris = satu **tender** (satu kali serah-bayar) untuk sebuah penjualan: `transaction_id` (FK cascade), `payment_method`, `amount_minor`, `payment_verification` (default `cashier_recorded`), `reference`/`notes` opsional; uuid/tenant_id/version. Sebelumnya satu penjualan hanya sanggup mewakili satu metode (`transactions.payment_method` + `paid_minor`).

### Aksi Domain
- **`Sales\CreateSale`** — menerima `payments: list<{payment_method, amount_minor, reference?, notes?}>` (amandemen **aditif**; jalur lama `payment_method` + `paid_minor` tidak berubah, termasuk penjualan kredit tanpa bayar):
  - penyelesaian pembayaran dipusatkan di `settlePayment()` / `settleSplitPayment()` → satu tempat memutuskan metode, nominal, kembalian, dan daftar tender;
  - **jumlah seluruh tender = `paid_minor`**; bila `paid_minor` ikut dikirim, ia wajib cocok (menolak dua sumber kebenaran);
  - **total tender non-tunai tidak boleh melebihi total** → kembalian dijamin hanya berasal dari tunai;
  - lebih dari satu tender mencatat `payment_method = mixed`; satu tender lewat `payments` tetap metode tunggal (QRIS tunggal tetap wajib tepat total);
  - aturan FR-05 lama tetap: metode instruksi (transfer manual, QRIS statis) tidak pernah "terverifikasi", QRIS statis menuntut tenant yang sudah mengunggah QRIS, metode di luar katalog ditolak;
  - **setiap** penjualan (termasuk metode tunggal) kini menulis baris `transaction_payments`, sehingga laporan per metode punya satu sumber;
  - `syncPayload` membawa tender (metode tunggal → `payments: null`, agar replay server memakai jalur lama yang teruji).

### Model, Factory & Enum
- **`Enum\PaymentMethod`** — nilai aditif `mixed` ("Campuran") yang **hanya** dihasilkan `CreateSale`; ditolak bila dikirim sebagai `payment_method` langsung, dan dikecualikan dari pemilih metode tunggal lewat `PaymentMethod::selectable()`.
- **`TransactionPayment`** — model tender (BelongsToTenant, HasSyncIdentity, JournalSyncChanges; `payment_method` sengaja tidak di-cast ke enum agar data impor lama tetap terbaca; `isCash()`).
- **`TransactionPaymentFactory`**; **`Transaction`** — relasi `payments()` + `isSplitPayment()`.

### UI (Folio/Volt)
- **`/transactions/create`** (kasir) — saklar **"Split payment"**: baris tender (metode + nominal) bisa ditambah/dihapus; ringkasan "Total dibayar"/"Sisa"; peringatan QRIS belum dikonfigurasi; validasi (minimal dua tender bernominal, non-tunai ≤ total, `mixed` tidak boleh dipilih); modal konfirmasi menampilkan rincian tender. Alur metode tunggal tidak berubah.
- **`/transactions/[Transaction]/receipt`** — pembayaran campuran dicetak sebagai rincian per tender (transaksi lama tetap satu baris "Bayar (metode)").
- **`/transactions/[Transaction]`** — "mixed" tidak lagi bisa dipilih sebagai metode tunggal saat menyunting.
- **`/reports`** + **`GenerateCashFlowReport`** — label "Mixed" dan pembayaran campuran ikut dihitung sebagai arus kas masuk (`paid_minor` tetap seluruh uang yang diterima).

### Pengujian
- **`tests/Feature/Payments/SplitPaymentTest.php`** (19) — dua/tiga tender → mixed; kembalian dari tunai saat tunai lebih bayar; non-tunai > total ditolak; `paid_minor` tak cocok ditolak; tender ≤ 0 ditolak; transfer tidak boleh "terverifikasi"; QRIS tanpa konfigurasi ditolak & QRIS terkonfigurasi diterima; QRIS tunggal wajib tepat total; kredit sebagian → piutang atas sisa; kredit tanpa pelanggan ditolak; replay idempoten tanpa menggandakan tender; metode tunggal tetap menulis satu baris; payload sinkronisasi perangkat membawa tender (dan `null` untuk metode tunggal); `mixed` sebagai `payment_method` langsung ditolak; metode tender asing ditolak.
- **`tests/Feature/Payments/SplitPaymentCheckoutUiTest.php`** (11) — saklar split tampil & "Campuran" tidak muncul sebagai metode; simpan split merekam tiap tender; kembalian tunai; split dengan satu tender bernominal diblokir; non-tunai > total diblokir; tender QRIS tanpa konfigurasi diblokir / dengan konfigurasi diterima; validasi metode asing & `mixed`; tambah/hapus baris tender; alur metode tunggal tidak berubah.

## Status suite
- **696 passed / 0 failed / 4 skipped** (2271 assertions). Pint bersih (13 file, 4 style issues).
- Migrasi `000006` dijalankan di dev.

---

# Rebuild POS Universal — Fase 5: Promosi & Diskon Otomatis (FR-06, Increment 41) (belum dirilis)

**Tanggal:** 12 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (§5.1 MVP #8 "Diskon dengan aturan dan izin yang jelas"; FR-06 Diskon: nominal, persen, per produk, seluruh transaksi, promosi berdasarkan periode; batas diskon; snapshot historis).

## Perubahan

### Migrasi
- **`2026_10_12_000005_create_promotions_tables.php`**:
  - `promotions` — promosi diskon (name, code, `discount_type` percent|nominal, `discount_value`, `scope` product|transaction, `min_purchase_minor`, `max_discount_minor` (cap, nullable), `starts_at`/`ends_at`, `is_active`, `priority`, notes; uuid/tenant_id/version/soft-deletes).
  - `promotion_products` — pivot M2M (promotion_id, product_id, unique `[promotion_id, product_id]`, FK cascade). Pivot **bukan** tabel sync → `uuid` nullable.
  - Snapshot aditif pada `transactions` (`promotion_id` FK nullOnDelete, `promotion_name`, `promotion_discount_minor`) dan `transaction_items` (`promotion_id`, `promotion_name`) — FR-06 "transaksi harus menyimpan snapshot harga dan diskon".

### Model, Factory & Support
- **`Promotion`** — model promosi (BelongsToTenant, HasSyncIdentity, SoftDeletes, JournalSyncChanges; relasi `products()`; scope `activeAt()`; helper `discountFor()` dengan aturan non-negatif + clamp subtotal + cap; `label()`).
- **`PromotionFactory`** — state `percent()`, `nominal()`, `productScope()` (deterministik: min purchase & cap eksplisit) untuk pengujian.
- **`Transaction` / `TransactionItem`** — field snapshot promosi ditambahkan ke `$fillable` + cast integer.

### Aksi Domain
- **`Promotions\EvaluatePromotions`** — evaluasi murni (tanpa tulis). Aturan penggabungan eksplisit (FR-06): **satu promosi terbaik per produk** + **satu promosi terbaik per transaksi**; kedua lapisan boleh bergabung; `min_purchase_minor` dihitung dari subtotal; nilai diskon tidak pernah negatif, di-clamp ke subtotal, dan di-cap `max_discount_minor`; periode divalidasi terhadap waktu evaluasi; tie-break `priority` tertinggi.
- **`Sales\CreateSale`** — menerima snapshot promosi opsional (amandemen additif, lintasan lama tak berubah): per baris `promotion_id`/`promotion_name`, transaksi `promotion_id`/`promotion_name`/`promotion_discount_minor`; ikut dibawa di `syncPayload` untuk replay sinkronisasi.

### UI (Folio/Volt)
- **`/promotions`** — CRUD promosi: tabel (search, paginate; tipe, scope, nilai, syarat, periode, status), create/edit modal dengan multiselect produk (saat scope=product), toggle aktif, hapus (soft delete — snapshot historis tetap utuh).
- **`/transactions/create`** (kasir) — panel promosi: `EvaluatePromotions` dievaluasi terhadap keranjang secara live; ringkasan & modal konfirmasi menampilkan "Diskon" (promo otomatis bila tidak ada diskon manual) + baris "Promo: <nama>". **Aturan penggabungan**: diskon manual menang atas promosi otomatis (kasir override); bila manual kosong, promosi otomatis berlaku. Snapshot promosi ikut dikirim ke `CreateSale` saat checkout.

### Modul & Otorisasi (FR-13)
- **`Module::Promotions`** (`promotions`) kini diaktifkan di profil `retail` (sudah ada di `advanced`).
- Link sidebar "Promosi" di grup Penjualan (`@can('promotions.view')`); seeder `RolePermissionSeeder` menambah grup permission `promotions.{view,create,edit,delete}` (pemilik diberi akses).

### Pengujian
- **`tests/Feature/Promotions/EvaluatePromotionsTest.php`** (15) — keranjang kosong; persen/nominal transaksi; gate min purchase; gate periode (kedaluwarsa/belum mulai); cap; clamp non-negatif; promo produk hanya untuk produk terdaftar; best-of per produk; tie-break prioritas; gabung lapisan baris + transaksi; promosi non-aktif diabaikan; isolasi antar-tenant; pembulatan persen; pengelompokan baris per produk.
- **`tests/Feature/Promotions/PromotionPageTest.php`** (12) — render; create (transaksi & produk + selected_products); validasi (name/value, scope produk wajib pilih produk); `max_discount_minor` kosong → null (bukan 0); edit; toggle aktif; delete (soft); visibilitas sidebar (Retail tampil, Simple tersembunyi).
- **`tests/Feature/Promotions/CreateSalePromotionTest.php`** (3) — snapshot promosi tersimpan di transaksi & item; penjualan tanpa promosi tetap null; menghapus promosi tidak mengubah transaksi historis.

## Status suite
- **666 passed / 0 failed / 4 skipped** (2180 assertions). Pint bersih (12 file, 7 style issues).
- Migrasi `000005` dijalankan di dev (rollback+migrate ulang setelah `promotion_products.uuid` dibuat nullable).

---

# Rebuild POS Universal — Fase 5: Resep & Bahan Baku F&B (Increment 40) (belum dirilis)

**Tanggal:** 12 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 5 — modul lanjutan F&B: resep menu, bahan baku, HPP otomatis, auto-deduct stok saat order diproses).

## Perubahan

### Migrasi
- **`2026_10_12_000004_create_ingredients_and_recipes_tables.php`** — tabel baru `ingredients` (bahan baku: name, slug, sku, unit basis, stock_qty, cost_minor per 1 unit, min_stock_qty, is_active, notes; uuid/tenant_id/version/soft-deletes) dan `recipe_ingredients` (menu_id, ingredient_id, quantity per 1 porsi, notes; unique `[menu_id, ingredient_id]`, FK cascade ke `menus`/`ingredients`).
- Self-healing: di awal `up()`, drop tabel `recipes` + `recipe_ingredients` dari desain lama (migrasi `2026_10_11_000006`, berbasis produk) yang kosong & tidak direferensikan kode mana pun.
- `stock_qty` sengaja **signed** (bukan unsigned) — auto-deduct boleh membuat stok negatif hingga stok masuk (keputusan Increment 40).

### Model, Enum & Support
- **`Ingredient`** — model bahan baku (HasSyncIdentity, BelongsToTenant, SoftDeletes; accessor `cost`).
- **`RecipeIngredient`** — baris komposisi resep (belongsTo menu & ingredient).
- **`Menu`** — + relasi `recipeIngredients()`, accessor `cogs_minor` (HPP 1 porsi = sum(qty × cost_minor bahan)), `has_recipe`, `price`.
- **`AuditAction`** — + `RecipeUpdated` (`recipe.updated`) dan `OrderStatusChanged` (`order.status_changed`).
- **`InvalidOrderTransition`** — exception transisi status pesanan tidak sah (`app/Exceptions/Orders/`).

### Aksi Domain
- **`Menus\UpdateRecipe`** — replace atomik komposisi resep: force-delete baris lama + insert baru dalam satu transaksi; validasi duplikat bahan, qty > 0, bahan harus milik tenant; audit `recipe.updated` (items_count, total_cogs_minor); HPP menu dihitung ulang otomatis.
- **`Orders\AdvanceOrderStatus`** — mesin status `pending → preparing → ready → served → paid` (+ `cancelled` dari pending/preparing/ready):
  - `pending → preparing` : auto-deduct bahan baku sesuai resep × qty item.
  - `preparing/ready → cancelled` : stok bahan dikembalikan (restore).
  - Transisi lain hanya mengubah label; stok tidak disentuh. Atomic (DB transaction + lock) & idempoten; audit `order.status_changed` (status_from/to, inventory_adjusted, lines_count).

### UI (Folio/Volt)
- **`/ingredients`** — CRUD bahan baku: tabel (search, paginate), modal create/edit, delete; subheading HPP & unit basis.
- **`/menu`** — setiap kartu menu menampilkan **HPP** + badge margin (%) bila resep ada, atau badge "Belum ada resep"; tombol **Resep** membuka modal komposisi (pilih bahan + jumlah/porsi, tambah/hapus baris, total HPP live), simpan via `UpdateRecipe`.
- **`/orders`** — update status kini memanggil `AdvanceOrderStatus`: toast sukses menyebut "stok bahan disesuaikan" bila ada deduksi, toast danger saat `InvalidOrderTransition`.

### Modul & Otorisasi (FR-13)
- **`Module::Ingredients`** (`ingredients`, label "Bahan Baku") diaktifkan pada profil `food_beverage` dan `advanced`.
- Link sidebar "Bahan Baku" di grup Makanan & Minuman (`@can('ingredients.view')`); seeder `RolePermissionSeeder` menambah grup permission `ingredients.{view,create,edit,delete}`.

### Pengujian
- **`tests/Feature/Orders/AdvanceOrderStatusTest.php`** (12) — pending→preparing deduct sesuai resep × qty item; preparing→ready tidak deduct ganda; served/paid tanpa menyentuh stok; cancelled restore dari preparing & ready; cancelled dari pending tanpa efek; transisi invalid (throw, status & stok tetap); order tak dikenal; order tanpa resep; stok negatif diizinkan; audit log.
- **`tests/Feature/Menus/MenuRecipeTest.php`** (10) — create lines + HPP benar; replace atomik; clear dengan items kosong; tolak duplikat / qty ≤ 0 / bahan tak dikenal / bahan tenant lain / menu tak dikenal; audit `recipe.updated`.
- **`tests/Feature/Ingredients/IngredientPageTest.php`** (8) — render halaman; create/edit/delete via Volt; validasi wajib name & unit; visibilitas sidebar (profil F&B tampil, Retail tersembunyi).
- **`tests/Feature/Features/ModuleCatalogTest.php`** — katalog modul kini 16; profil F&B mengekspos modul `ingredients`.

## Status suite
- **636 passed / 0 failed / 4 skipped** (2105 assertions). Pint bersih (16 file, 10 style issues).

---

# Rebuild POS Universal — Fase 5: Laporan Lanjutan (Increment 39) (belum dirilis)

**Tanggal:** 12 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 5 "Laporan lanjutan" — margin historis, pergerakan stok, arus kas, aging piutang, perbandingan periode; §5.2 versi berikutnya: "Laporan margin, pergerakan stok, arus kas yang lebih mendalam", "Pelanggan, pemasok, dan piutang tingkat lanjut").

## Perubahan

### Aksi Laporan (App\Actions\Reports\)
- **`GenerateMarginHistoryReport`** — margin kotor historis per periode (harian/mingguan/bulanan): revenue dari `sum(transaction_items.subtotal)`, COGS dari `sum(products.buy_price * qty)`, margin absolute & persen. Aggregate minggu/bulan dilakukan di PHP agar query portabel SQLite/MySQL. Juga `getProductMargins()` (margin per produk) dan `getTotals()`.
- **`GenerateStockMovementReport`** — mutasi stok masuk/keluar per produk dengan filter produk & tipe referensi; `getSummaryByType()`, `getDailyChartData()`.
- **`GenerateCashFlowReport`** — arus kas: pemasukan (penjualan tunai, pelunasan piutang, refund retur pembelian) dan pengeluaran (PO diterima, pembayaran supplier); `getTotals()`, `getSummaryByCategory()`, `getDailyChartData()` (3 seri: masuk/keluar/net).
- **`GenerateAgingReceivablesReport`** — umur piutang dengan bucket Current / 1-30 / 31-60 / 61-90 / >90 hari (as-of date); `getAgingSummary()`, `getCustomerAging()`.
- **`GenerateSupplierPaymentReport`** — riwayat pembayaran supplier + hutang outstanding per supplier (balance_minor, nilai PO aktif); `getTotals()`, `getSupplierOutstanding()`.
- **`GeneratePurchaseVsSalesReport`** — perbandingan qty & nilai pembelian vs penjualan per produk, selisih stok, rasio perputaran, status stok (Healthy/Overstock/Understock/Dead Stock/Stock Out Risk); `getStatusSummary()`, `getChartData()`.
- Konvensi: `tenant_id` bertipe **string (UUID)** karena `Tenant` memakai `HasUuids`; kolom money memakai integer minor units.

### Halaman Laporan Lanjutan (Folio, all `auth` + `verified`)
- **`/reports/margin`** — filter tanggal + kelompokkan (hari/minggu/bulan), 4 kartu total (pendapatan, HPP, margin, rata-rata %), chart tren ApexCharts (line), tabel margin per produk.
- **`/reports/stock-movement`** — filter tanggal + produk, kartu total masuk/keluar, ringkasan per sumber mutasi, tabel detail (awal/akhir/perubahan stok).
- **`/reports/cash-flow`** — filter tanggal, kartu total masuk/keluar/net, chart bar harian (masuk/keluar/net), ringkasan per kategori, tabel detail.
- **`/reports/aging`** — input as-of date, 5 kartu bucket, tabel ringkasan per pelanggan, tabel detail piutang dengan badge umur.
- **`/reports/supplier-payments`** — filter tanggal + supplier, kartu total dibayar/hutang/PO belum lunas, tabel hutang per supplier, tabel riwayat pembayaran.
- **`/reports/purchase-vs-sales`** — filter tanggal, kartu ringkasan status stok, chart bar (pendapatan vs biaya pembelian top 10), tabel detail per produk.
- **`/reports` (index)** — menambah kartu navigasi "Advanced Reports" menautkan ke 6 laporan lanjutan.

### Pengujian
- **`tests\Feature\Reports\AdvancedReportsTest.php`** (8) — aksi laporan: stock movement (range + filter produk), arus kas (in/out/net), aging buckets, margin (day + weekly aggregation + per produk), supplier payment totals, purchase vs sales (qty diff + status stok).
- **`tests\Feature\Reports\AdvancedReportsPagesTest.php`** (6) — render 6 halaman laporan baru (HTTP 200).

## Status suite
- **607 passed / 0 failed / 4 skipped** (2004 assertions). Pint bersih.

---

# Rebuild POS Universal — Fase 5: Pembelian & Penerimaan Barang Lengkap (FR-13, Increment 38) (belum dirilis)

**Tanggal:** 12 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 5 — "Profil F&B dan Credit Sales"; FR-13 profil mengatur visibilitas menu; Retail profile includes Purchases & Suppliers).

## Perubahan

### Migrasi & Model baru — Sync fields untuk tabel pembelian
- **`2026_10_12_000002_add_sync_fields_to_purchase_tables.php`** — menambah kolom sync ke tabel existing:
  - `purchase_orders`: uuid, tenant_id, version, soft deletes, subtotal_minor, discount_minor, total_minor, location_id, device_id, client_request_id.
  - `purchase_order_items`: uuid, tenant_id, version, soft deletes, unit_price_minor, discount_minor, subtotal_minor.
  - `goods_receipts`: uuid, tenant_id, version, soft deletes, location_id, device_id, client_request_id.
  - **Tabel baru** `goods_receipt_items`: item penerimaan barang per PO item.
  - `purchase_returns`: uuid, tenant_id, version, soft deletes, subtotal_minor, discount_minor, total_minor, location_id, device_id, client_request_id.
  - `purchase_return_items`: uuid, tenant_id, version, soft deletes, price_minor, discount_minor, subtotal_minor.
  - `supplier_payments`: uuid, tenant_id, version, soft deletes, amount_minor, location_id, device_id, client_request_id.
  - `suppliers`: uuid, tenant_id, version, soft deletes, balance_minor.
- **Model update** dengan trait `BelongsToTenant`, `HasSyncIdentity`, `JournalSyncChanges`, `SoftDeletes`; uang integer minor units (IDR eksponen 0).

### Domain Actions (App\Actions\Purchases\)
- **`CreatePurchaseOrder`** — atomic PO creation dengan items, supplier balance update, idempotensi via `client_request_id`, outbox enqueue untuk sync.
- **`ReceiveGoods`** — penerimaan barang parsial/penuh, update `received_quantity`, increment stok via `StockMovement`, update PO status (`partial`/`received`), audit log, idempotensi, outbox enqueue.
- **`CreatePurchaseReturn`** — retur pembelian ke supplier, validasi qty tidak melebihi received, decrement stok, update supplier balance, audit log, idempotensi, outbox enqueue.
- **`RecordSupplierPayment`** — pencatatan pembayaran hutang supplier, validasi tidak melebihi balance, update supplier balance_minor, audit log, idempotensi, outbox enqueue.

### Halaman Pembelian (`/purchases`, `/purchases/create`, `/purchases/{po}`)
- **Index**: tabel dengan filter search, kolom Total pakai `total_minor`, badge status warna.
- **Create**: keranjang produk dengan pencarian, unit selector modal, harga & diskon per item (minor units), total otomatis, simpan draft atau submit pending.
- **Show**: detail PO dengan items, workflow receive goods modal (qty per item, notes), goods receipt history, cancel PO.

### Halaman Retur Pembelian (`/returns/purchase`)
- Search PO by nomor, tampilkan item yang bisa diretur (max qty = received), input qty retur & reason per item, diskon overall, total otomatis.

### Halaman Hutang Supplier (`/payments/supplier`)
- Daftar riwayat pembayaran supplier, modal record payment dengan search supplier, amount, metode, notes, update balance supplier otomatis.

### Sidebar — grup "Pembelian" (existing, enhanced)
- **Purchase Order** (ikon truck), **Retur Pembelian** (ikon arrow-uturn-left), **Hutang Supplier** (ikon banknotes) — muncul bila modul `Purchases` atau `Suppliers` aktif, butuh izin `purchases.view`.

### Pengujian
- **`tests\Feature\Purchases\PurchaseOrderTest.php`** (3): index page, create page, show page dengan model binding resolver.

## Status suite
- **593 passed / 0 failed / 4 skipped** (1960 assertions). Pint bersih.

---

# Rebuild POS Universal — Fase 5: Profil F&B — Menu & Pesanan (FR-13, Increment 37) (belum dirilis)

**Tanggal:** 12 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 5 — "Profil F&B dan Credit Sales"; Persona C §3.1 "kebutuhan: produk, variasi, modifier sederhana, diskon, penjualan, laporan harian; fitur tambahan: catatan pesanan dan status pesanan"; FR-13 profil mengatur visibilitas menu; tabel 3.2 profil Food & Beverage → "Kasir, Menu, Pesanan, Laporan").

## Perubahan

### Migrasi & Model baru
- **`2026_10_12_000001_create_menus_and_orders_tables.php`** — tiga tabel baru:
  - `menus` — item menu F&B (uuid, tenant_id, category_id, name, slug, sku, barcode, description, price_minor, preparation_time, is_available, is_featured, image, soft deletes).
  - `orders` — pesanan dengan status workflow (uuid, tenant_id, customer_id, customer_name, order_number, table_number, subtotal_minor, discount_minor, total_minor, paid_minor, change_minor, status `pending|preparing|ready|served|paid|cancelled`, payment_method, payment_status `unpaid|partial|paid`, notes, user_id, soft deletes).
  - `order_items` — item pesanan (uuid, tenant_id, order_id FK cascade, menu_id, menu_name snapshot, unit_price_minor, quantity, subtotal_minor, discount_minor, notes, soft deletes).
- **`App\Models\Menu`**, **`App\Models\Order`**, **`App\Models\OrderItem`** — memakai trait `BelongsToTenant`, `HasSyncIdentity`, `JournalSyncChanges`; uang integer minor units (IDR eksponen 0 → 1 minor = Rp1).
- **Factory** `MenuFactory`, `OrderFactory`, `OrderItemFactory` — mendukung state `unavailable`, `featured`, `pending`, `paid`, dll.

### Halaman Menu (`/menu`, `menu.index`)
- Grid card responsif (1–4 kolom) dengan gambar, badge "Featured", harga, waktu persiapan, status tersedia/tidak.
- Filter pencarian (nama, SKU, barcode) + filter kategori.
- Modal **Create** & **Edit** (nama, kategori, SKU, barcode, deskripsi, harga, waktu persiapan, tersedia, featured, upload gambar).
- Aksi hapus via dropdown di card.

### Halaman Pesanan (`/orders`, `orders.index`)
- Tabel dengan filter status (pending/preparing/ready/served/paid/cancelled) + pencarian (nomor, pelanggan, meja).
- Kolom: Order #, Pelanggan, Meja, Total, Dibayar, Status (badge warna), Payment (badge warna), Waktu, Actions.
- **Workflow status** via dropdown: `pending` → `preparing` → `ready` → `served` → `paid`; `cancelled` hanya dari `pending`.
- **Modal detail** (view order): ringkasan pelanggan/meja/kasier, daftar item (qty, harga, subtotal), footer subtotal/diskon/total, catatan.
- **Modal pembayaran**: nominal tersisa otomatis, metode (cash/transfer/QRIS/card), catatan; update `paid_minor`, `change_minor`, `payment_status`, dan status order menjadi `paid` saat lunas.

### Sidebar — grup baru "Makanan & Minuman"
- Muncul bila modul `Menu` atau `Orders` aktif (`FeatureProfileManager::isEnabled`).
- **Menu** → ikon `list-bullet`, butuh izin `products.view`.
- **Pesanan** → ikon `clipboard-document-list`, butuh izin `transactions.view`.

### Pengujian
- **`tests\Feature\Menu\MenuIndexTest.php`** (2): halaman index menampilkan item; badge Featured terlihat.
- **`tests\Feature\Orders\OrderIndexTest.php`** (3): halaman index; filter status via URL (`?status=pending`); modal detail menampilkan item.

## Status suite
- **590 passed / 0 failed / 4 skipped** (1956 assertions). Pint bersih pada file yang diubah.

---

# Rebuild POS Universal — Fase 5: Detail Pelanggan & Pembayaran Piutang UI (FR-07, FR-13, Increment 36) (belum dirilis)

**Tanggal:** 12 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 5 — "Profil F&B dan Credit Sales"; Persona E §3.1 "data pelanggan, limit kredit, piutang, jatuh tempo, pembayaran sebagian, riwayat penagihan"; FR-07 "Piutang: pencatatan, jatuh tempo, sisa saldo"; FR-13 profil mengatur visibilitas menu).

## Perubahan

### Halaman detail pelanggan (`resources\views\pages\customers\[Customer]\show.blade.php`, route `/customers/{customer}/show`, nama `customers.show`)
- **Ringkasan pelanggan**: info kontak, limit kredit, total tunggakan terbuka + sisa limit tersedia.
- **Tabel piutang terbuka**: invoice, tanggal, jatuh tempo (+ badge "Overdue"), nominal awal, sudah dibayar, sisa; aksi **Record Payment** per invoice dan **View Transaction**.
- **Riwayat pembayaran** (paginasi 10): tanggal, invoice, nominal, metode, catatan.
- **Modal pembayaran**: nominal terisi otomatis sebesar sisa piutang; metode (cash/transfer/QRIS/card); catatan. Tombol "View" ditambahkan di menu aksi halaman indeks pelanggan.
- Alur pembayaran memakai aksi domain **`RecordReceivablePayment`** (atomik + audit): `user_id` = kasir login, **`client_request_id` UUID per pembukaan modal (idempoten, anti double-click)**, validasi nominal ≤ sisa di UI dan di domain, error domain (`InvalidPaymentAmount`/`ReceivableClosed`) ditampilkan sebagai toast tanpa 500.

### Perbaikan bug yang ditemukan saat pengujian
- **Halaman 404 (`request()->route('customer')` + `findOrFail`)**: Folio sudah melakukan model-binding segmen `[Customer]`, sehingga nilai route adalah model — `findOrFail(model)` selalu 404. Berlaku juga di halaman edit bawaan. Di halaman show diperbaiki dengan injeksi `mount(?Customer $customer)` (pola yang sama dipakai halaman transaksi/struk) + resolver fallback yang menerima model maupun id mentah.
- **Skala uang**: IDR memakai eksponen minor 0 (`Money` + `config pos.currency.minor_exponent`) — 1 minor == Rp1. Konversi `/100` yang sempat tertulis di draf dihapus; sisa limit = `credit_limit − total_tunggakan` (keduanya rupiah).
- **Computed Volt**: tidak lagi menangkap closure `$customer` via `use` (fatal), melainkan `$this->customer`; judul layout dibuat statis (`Customer Detail`).
- **`CustomerFactory`**: `credit_limit` (`optional(0.6)`) dan `price_tier` (`optional(0.3)`) bisa menghasilkan `null` yang melanggar kolom NOT NULL → diganti nilai non-null (limit acak, tier default `retail`).
- **Registrasi Folio ganda** (efek samping sesi sebelumnya): `Folio::route()` di `routes/web.php` + `Folio::path()` di `FolioServiceProvider` mendaftarkan dua fallback/mount-path. `web.php` dan provider dikembalikan ke keadaan semula (satu pendaftaran).

### Pengujian
- **`tests\Feature\Customers\CustomerShowTest.php`** (2): halaman menampilkan piutang terbuka; menampilkan riwayat pembayaran.
- **`tests\Feature\Customers\CustomerIndexTest.php`** (1): indeks menampilkan pelanggan (dibuat sesi sebelumnya, dipertahankan).
- **`tests\Feature\Customers\CustomerPaymentUiTest.php`** (5, Livewire/Volt dengan `app.debug=false`): modal terisi sisa piutang + request id; bayar sebagian mengurangi sisa (status tetap `open`, `recorded_by` = kasir); bayar lunas menutup piutang (`remaining 0`, `closed`); kelebihan bayar ditolak tanpa mencatat; kirim ulang dengan `client_request_id` sama tidak menggandakan pembayaran.

## Status suite
- **585 passed / 0 failed / 4 skipped** (1946 assertions). Pint bersih pada file yang diubah.

---

# Rebuild POS Universal — Fase 5: Laporan Piutang (FR-07, Increment 34) (belum dirilis)

**Tanggal:** 12 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 5 — "Laporan lanjutan"; FR-07 "Piutang: pencatatan, jatuh tempo, sisa saldo").

## Perubahan

### Laporan Piutang (Credit Sales)
- **`app\Actions\Reports\GenerateReceivableReport`**: aksi domain untuk menghasilkan laporan piutang aktif (`status = 'open'`), mengembalikan collection dengan: `invoice_number`, `customer_name`, `amount_minor`, `amount_formatted` (IDR), `created_at`, `due_date`, `status`. Hanya piutang terbuka yang dilaporkan (`paid`/`closed` dikecualikan).
- **`resources\views\pages\reports\index.blade.php`**: tambah tabel **Laporan Piutang (Credit Sales)** — kolom Pelanggan, No. Invoice, Jumlah Piutang, Tgl Buat, Jatuh Tempo; badge merah "Belum Lunas" dengan jumlah; hanya muncul bila ada piutang terbuka.

### Pengujian
- **`tests\Feature\Reports\GenerateReceivableReportTest.php`** (2): piutang terbuka masuk laporan; piutang tertutup (`status = 'closed'`, `remaining_minor = 0`) dikecualikan.

---

# Rebuild POS Universal — Fase 5: Profil Bisnis & Visibilitas Menu (FR-13, Increment 35) (belum dirilis)

**Tanggal:** 12 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 5 — "Profil F&B dan Credit Sales"; FR-13 "Setiap toko punya `business_type`, `feature_profile`, `enabled_modules`, `ui_complexity`, `onboarding_state`"; "Profil tidak boleh menghapus data ketika fitur dimatikan. Pengaktifan kembali fitur harus memulihkan akses ke data yang sebelumnya tersedia").

## Perubahan

### `FeatureProfileManager` + Sidebar Visibility
- **`app\Support\Features\FeatureProfileManager`**: perbaiki resolusi modul — gunakan enum `FeatureProfile` dari model `Tenant` (sudah di-cast), bukan string mentah. `enabled_modules` null → pakai default profil; eksplisit → override per-tenant.
- **`resources\views\layouts\app\sidebar.blade.php`**: visibilitas grup menu dikontrol `FeatureProfileManager::isEnabled(Module::[NAMA])`:
  - **Penjualan**: tampil bila `Cashier` atau `History` atau `Customers` atau `Receivables` aktif
  - **Pembelian**: tampil bila `Purchases` atau `Suppliers` aktif
  - **Master Data**: tampil bila `Products` atau `Suppliers` atau `Customers` aktif
  - **Inventory**: tampil bila `Stock` aktif
  - **Laporan**: tampil bila `Reports` atau `ReportsAdvanced` aktif
- **Profil yang didukung** (`FeatureProfile` enum):
  - `Simple` → `cashier`, `products`, `history`, `reports`
  - `Retail` → `cashier`, `products`, `history`, `stock`, `purchases`, `suppliers`, `reports`
  - `CreditSales` → `cashier`, `customers`, `receivables`, `reports`
  - `FoodBeverage` → `cashier`, `menu`, `orders`, `reports`
  - `Advanced` → semua modul
- **Aturan**: mematikan modul hanya menyembunyikan menu (visibilitas), **tidak** menghapus data; mengaktifkan kembali memulihkan akses penuh (FR-13).

### TenantFactory
- **`database/factories/TenantFactory.php`**: `enabled_modules` diubah ke `null` (bukan `Simple->modules()`) agar default profil berlaku.

### Pengujian
- **`tests\Feature\Layout\SidebarFeatureProfileTest.php`** (2): `Simple` profile menyembunyikan Pembelian & Pelanggan; `CreditSales` profile menampilkan Pelanggan & Piutang, menyembunyikan Pembelian.
- **`tests\Feature\Foundation\TenantFoundationTest.php`** (updated): `enabled_modules` default `null`; `FeatureProfileManager` resolve modul dari profil.

## Status suite
- **577 passed / 0 failed / 4 skipped** (1924 assertions).

---

# Rebuild POS Universal — Fase 5: barcode kamera (§20, FR-03) (belum dirilis)

**Tanggal:** 11 Oktober 2026
**Referensi:** `.ai/plan/PRD, TDD, SAD.md` (Fase 5 — "Barcode kamera"; §20 "Printer, barcode, kamera, dan hardware" — "Kamera browser dengan izin pengguna untuk web"; §20 "Kamera" — "Jika gagal, tawarkan input manual"; FR-03 "Input produk melalui barcode, kamera, OCR, dan manual").

## Perubahan

### Barcode kamera (§20, FR-03)
- **Dependency baru `@zxing/library@^0.23.0`** (Apache-2.0, 0 vulnerabilitas, 1,3M+ unduhan/minggu) — library decode barcode yang matang dan teruji. Decode berjalan **penuhnya di sisi klien** (tanpa internet, tanpa unggah gambar) — sesuai prinsip offline-first untuk build native. Chunk di-split otomatis oleh Vite (dynamic import) sehingga hanya dimuat saat tombol scan ditekan.
- **`resources/js/barcode-scanner.js`**: modul scan dengan `openBarcodeScanner()` / `closeBarcodeScanner()`. Memeriksa `window.isSecureContext` + `navigator.mediaDevices.getUserMedia` sebelum dipakai (FR-03: konteks aman wajib); menggunakan `decodeFromVideoDevice(null, …)` ZXing yang memakai `facingMode: 'environment'` (fallback aman di desktop/kamera depan saja); hanya hasil decode pertama yang diproses; `reader.reset()` menghentikan kamera + loop decode secara bersih.
- **`resources/js/app.js`**: memaparkan `window.posBarcodeScanner` sebagai API global untuk Alpine di halaman POS.
- **`resources/css/app.css`**: aturan `[x-cloak]` untuk menyembunyikan elemen sebelum Alpine siap.
- **Halaman POS (`transactions/create.blade.php`)**:
  - Tombol kamera di samping input pencarian — membuka overlay `<video>` untuk scan.
  - Aksi Volt **`scanBarcode($barcode)`**: selalu memperbarui `productSearch` agar hasil scan terlihat di grid; barcode produk yang cocok persis → langsung buka pemilih satuan (alur identik dengan tombol "+"); barcode satuan (`product_units.barcode`) yang cocok → buka pemilih satuan dengan satuan tersebut terpilih sejak awal; tidak cocok → peringatan toast; kasir tetap bisa mencari/menambah manual.
  - **`openUnitSelector($productId, $unitId = null)`** diperluas: satuan opsional dapat dipilih sejak awal (dipakai saat scan barcode satuan).
- **FR-03 "input manual tetap tersedia"**: input pencarian manual tetap berfungsi sepenuhnya; kamera hanya tambahan.

### Catatan pengujian
- Kamera/decode tidak dapat diotomasi di PHPUnit (butuh perangkat keras/浏览器 nyata) — pengujian manual di browser wajib. Yang dikunci di tes adalah perilaku aksi domain `scanBarcode`.

## Status suite
- **567 passed / 0 failed / 4 skipped** (1903 assertions).

---
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

