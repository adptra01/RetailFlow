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

