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

