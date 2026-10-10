# POS Universal — Product Requirements Document, Software Architecture Document & Technical Design Document

**Versi:** 1.0  
**Status:** Draft untuk validasi teknis dan bisnis  
**Nama proyek:** POS Universal  
**Target platform:** Windows Desktop, Web/PWA, Android, dengan iOS sebagai target yang perlu divalidasi  
**Teknologi dasar:** Laravel, Alpine.js, Blade, NativePHP, SQLite, MySQL/PostgreSQL untuk server  
**Pendekatan:** Offline-first, modular monolith, shared domain logic, eventual synchronization

---

# BAGIAN I — PRODUCT REQUIREMENTS DOCUMENT (PRD)

## 1. Ringkasan produk

POS Universal adalah aplikasi kasir dan manajemen usaha yang dirancang untuk membantu pemilik usaha melakukan transaksi, mengelola produk dan persediaan, memantau keuntungan, mencatat utang-piutang, serta memahami kondisi bisnis tanpa membutuhkan pengetahuan teknis yang tinggi.

Produk dirancang agar dapat digunakan melalui Windows desktop, browser web, dan perangkat mobile. Semua platform mengikuti aturan bisnis, model data, konfigurasi, dan hak akses yang sama.

Produk tidak akan menampilkan semua fitur kepada semua pengguna. Sistem menggunakan profil usaha, persona, tingkat kompleksitas, dan izin pengguna untuk menampilkan fungsi yang relevan.

### Visi

Menjadi aplikasi POS yang mudah digunakan oleh pemilik usaha dengan tingkat literasi digital berbeda, tetap berfungsi ketika internet tidak tersedia, dan dapat berkembang dari kebutuhan toko kecil menjadi operasional usaha yang lebih kompleks.

### Misi

1. Mempermudah transaksi harian.
2. Mengurangi kesalahan pencatatan stok dan keuangan.
3. Memberikan laporan yang mudah dipahami.
4. Menyediakan pengingat otomatis berdasarkan kondisi bisnis.
5. Mengurangi beban konfigurasi dan pelatihan.
6. Menyediakan jalur pengembangan tanpa mengharuskan pengguna mengganti aplikasi ketika usahanya berkembang.

## 2. Masalah yang ingin diselesaikan

- Pencatatan penjualan manual rentan terhadap kesalahan.
- Pemilik usaha kesulitan mengetahui laba, omzet, dan stok yang sebenarnya.
- Aplikasi POS tertentu terlalu rumit untuk pengguna pemula.
- Koneksi internet tidak selalu tersedia atau stabil.
- Data kasir, stok, dan laporan sering terpisah.
- Pengelolaan piutang dan pembayaran parsial tidak terdokumentasi dengan baik.
- Pemilik usaha terlambat mengetahui stok menipis, piutang jatuh tempo, atau transaksi tidak biasa.
- Pemindahan data ke aplikasi baru sering menyulitkan pengguna.
- Perangkat kasir yang tersedia di toko seharusnya dapat dimanfaatkan tanpa membeli perangkat khusus.

## 3. Sasaran pengguna dan persona

### 3.1 Persona utama

**Persona A — Pemilik warung atau toko kecil**

- Kemampuan digital: dasar.
- Kebutuhan: kasir, produk, stok, transaksi, laporan sederhana.
- Perangkat: Android atau satu komputer Windows.
- Hambatan: terlalu banyak menu dan istilah teknis.
- Tampilan default: sederhana.

**Persona B — Kasir toko**

- Kemampuan digital: dasar sampai menengah.
- Kebutuhan: pencarian produk cepat, barcode, keranjang, pembayaran, cetak struk.
- Prioritas: sedikit klik, tombol besar, navigasi konsisten.
- Akses: hanya fungsi sesuai izin.

**Persona C — Pemilik usaha makanan/minuman**

- Kebutuhan: produk, variasi, modifier sederhana, diskon, penjualan, laporan harian.
- Fitur tambahan: catatan pesanan dan status pesanan jika diaktifkan.
- Potensi pengembangan: resep dan bahan baku sebagai modul lanjutan.

**Persona D — Pengelola toko retail/minimarket**

- Kebutuhan: banyak SKU, barcode, stok minimum, penyesuaian stok, retur, pembelian, laporan margin.
- Fitur tambahan: pemasok, penerimaan barang, audit stok, multi-kasir.

**Persona E — Pemilik toko yang memberi kredit**

- Kebutuhan: data pelanggan, limit kredit, piutang, jatuh tempo, pembayaran sebagian, riwayat penagihan.
- Fitur tambahan: pengingat piutang dan laporan umur piutang.

**Persona F — Pemilik usaha dengan beberapa lokasi**

- Kebutuhan: laporan terpusat, perpindahan stok, peran per lokasi, sinkronisasi antarperangkat.
- Persyaratan: server pusat dan kebijakan konflik stok.
- Bukan target implementasi pertama.

### 3.2 Profil tingkat kompleksitas

| Profil | Pengguna sasaran | Menu awal |
|---|---|---|
| Simple | Warung dan pengguna baru | Kasir, Produk, Riwayat, Laporan |
| Retail | Toko retail | Kasir, Produk, Stok, Pembelian, Laporan |
| Food & Beverage | Kedai dan usaha makanan | Kasir, Menu, Pesanan, Laporan |
| Credit Sales | Usaha yang menerima pembayaran tempo | Kasir, Pelanggan, Piutang, Laporan |
| Advanced | Toko yang lebih kompleks | Semua modul yang diizinkan |

Profil hanya mengatur konfigurasi dan visibilitas menu. Profil tidak boleh menjadi pengganti otorisasi keamanan.

## 4. Tujuan produk dan metrik keberhasilan

### Tujuan produk

- Pengguna baru dapat menyelesaikan transaksi pertama tanpa bantuan teknis.
- Transaksi dapat dilakukan tanpa internet pada perangkat yang sudah terdaftar dan memiliki data lokal.
- Semua transaksi yang telah dikonfirmasi tersimpan secara konsisten.
- Laporan penjualan dan stok dapat ditelusuri kembali ke transaksi sumber.
- Fitur lanjutan dapat diaktifkan tanpa mengubah data inti atau memasang aplikasi berbeda.

### Metrik awal

Target berikut adalah sasaran pengujian awal, bukan hasil yang sudah terbukti.

| Metrik | Target awal |
|---|---:|
| Waktu transaksi sederhana | Median kurang dari 30 detik |
| Keberhasilan checkout offline | Minimal 99% pada pengujian yang ditentukan |
| Duplikasi penjualan akibat pengulangan request | 0 |
| Kehilangan transaksi yang sudah dikonfirmasi | 0 pada pengujian penerimaan |
| Pemulihan dari backup | Berhasil pada seluruh skenario uji wajib |
| Pengguna uji yang dapat melakukan penjualan pertama | Minimal 90% tanpa bantuan |
| Konflik sinkronisasi yang tidak tercatat | 0 |

## 5. Ruang lingkup produk

### 5.1 MVP — wajib tersedia

1. Onboarding dan pengaturan usaha.
2. Profil toko dan lokasi.
3. Pengguna, autentikasi, dan peran dasar.
4. Produk, kategori, harga, barcode, satuan.
5. Impor produk melalui CSV.
6. Kasir dan keranjang belanja.
7. Pembayaran tunai, pembayaran campuran dasar, dan pencatatan piutang.
8. Diskon dengan aturan dan izin yang jelas.
9. Cetak atau simpan struk.
10. Riwayat penjualan dan pembatalan terkontrol.
11. Stok dan mutasi stok.
12. Laporan penjualan harian.
13. Pengingat stok minimum.
14. Backup dan restore lokal.
15. Status offline/online dan antrean sinkronisasi.
16. Pengaturan profil fitur berdasarkan jenis usaha.
17. Pengujian Windows offline dan web online.
18. Log audit untuk tindakan penting.

### 5.2 Versi berikutnya

- Integrasi payment gateway dan verifikasi pembayaran otomatis.
- Pembelian dan penerimaan barang lengkap.
- Pelanggan, pemasok, dan piutang tingkat lanjut.
- QRIS dinamis melalui penyedia resmi.
- Barcode scanner kamera di mobile.
- OCR untuk membantu input produk.
- Sinkronisasi multi-perangkat yang lebih lengkap.
- Laporan margin, pergerakan stok, dan arus kas yang lebih mendalam.
- Notifikasi push ketika perangkat mendukungnya dan memiliki koneksi.
- Multi-cabang, transfer stok, dan konsolidasi laporan.
- Dukungan resep dan bahan baku untuk F&B.

### 5.3 Bukan target MVP

- Akuntansi penuh pengganti software akuntansi.
- Payroll dan HR.
- Marketplace.
- Integrasi langsung ke setiap e-wallet tanpa perjanjian atau API resmi.
- Sinkronisasi peer-to-peer yang menjamin konsistensi global saat semua perangkat terputus.
- Dukungan universal untuk seluruh printer tanpa pengujian.
- Pengiriman notifikasi cloud ketika semua koneksi jaringan tidak tersedia.

## 6. Kebutuhan fungsional

### FR-01 — Onboarding

Sistem harus memungkinkan pengguna:

- Membuat profil usaha.
- Memilih jenis usaha dan profil kompleksitas.
- Menentukan mata uang, zona waktu, pajak, dan format struk.
- Menambahkan produk secara manual atau mengimpor CSV.
- Mengatur metode pembayaran yang digunakan.
- Mengatur printer jika tersedia.
- Menggunakan aplikasi tanpa menghubungkan payment gateway.

Pengguna harus dapat melewati konfigurasi opsional dan menyelesaikannya kemudian.

### FR-02 — Manajemen produk

Data minimum produk:

- Nama produk.
- SKU internal.
- Satu atau lebih barcode bila diperlukan.
- Kategori.
- Satuan.
- Harga jual.
- Harga modal jika diketahui.
- Status aktif/nonaktif.
- Stok tersedia jika pengelolaan stok diaktifkan.
- Ambang stok minimum.
- Foto opsional.

Ketentuan:

- SKU dan barcode harus divalidasi sesuai kebijakan keunikan toko.
- Produk yang pernah digunakan dalam transaksi tidak boleh dihapus permanen melalui antarmuka biasa.
- Perubahan harga tidak boleh mengubah harga historis pada transaksi lama.
- Pengguna dapat mengaktifkan atau menonaktifkan pelacakan stok per produk.
- Impor harus menyediakan pratinjau, laporan kesalahan, dan mekanisme koreksi sebelum menyimpan.

### FR-03 — Input produk melalui barcode, kamera, OCR, dan manual

**Barcode scanner eksternal**

- Scanner yang berperilaku seperti keyboard harus dapat digunakan tanpa integrasi khusus.
- Fokus input barcode harus dapat dipulihkan dengan mudah.
- Enter atau suffix scanner harus dapat memicu pencarian.
- Sistem tidak boleh menganggap setiap input keyboard sebagai scan barcode.

**Kamera**

- Perangkat mobile dapat menggunakan kamera untuk membaca barcode melalui kemampuan native atau library yang diuji.
- Web harus meminta izin kamera secara eksplisit dan berjalan pada konteks browser yang memenuhi persyaratan keamanan.
- Jika kamera tidak tersedia, input manual tetap tersedia.

**OCR**

- Pengguna dapat mengambil gambar label atau memasukkan foto.
- OCR menghasilkan kandidat nama, harga, barcode, atau informasi lain.
- Hasil OCR wajib ditampilkan sebagai draft untuk diperiksa pengguna.
- OCR tidak boleh langsung membuat harga atau stok final tanpa konfirmasi.
- Fitur OCR berbasis cloud harus menyatakan kebutuhan internet dan kebijakan pengiriman gambar.
- OCR lokal dapat menjadi opsi lanjutan jika ukuran model, performa, dan lisensi memungkinkan.

### FR-04 — Kasir

Alur transaksi:

1. Pengguna memilih atau memindai produk.
2. Produk masuk ke keranjang.
3. Pengguna mengubah kuantitas atau menghapus item sesuai izin.
4. Sistem menghitung subtotal, diskon, pajak, dan total.
5. Kasir memilih metode pembayaran.
6. Sistem memvalidasi stok, harga, diskon, dan izin.
7. Sistem menyimpan transaksi secara atomik.
8. Sistem mengurangi stok jika pelacakan stok aktif.
9. Sistem mencatat pembayaran dan mutasi keuangan.
10. Sistem menampilkan hasil transaksi dan opsi cetak struk.

Persyaratan:

- Tombol pembayaran harus mencegah pengiriman ganda.
- Kegagalan penyimpanan tidak boleh mengosongkan keranjang.
- Penjualan yang sudah tersimpan tidak boleh dibatalkan hanya karena printer gagal.
- Transaksi harus dapat dicetak ulang.
- Sistem harus menghasilkan nomor transaksi yang unik dalam lingkup toko.
- Harga dan stok harus divalidasi ulang oleh lapisan domain saat checkout.
- Semua nilai uang menggunakan tipe numerik yang aman, bukan floating-point untuk perhitungan moneter.

### FR-05 — Pembayaran

Metode yang direncanakan:

- Tunai.
- Transfer bank yang dicatat manual.
- QRIS statis.
- QRIS dinamis melalui gateway yang didukung.
- E-wallet melalui penyedia resmi.
- Pembayaran campuran.
- Penjualan kredit/piutang.

Ketentuan penting:

- QRIS statis yang diunggah pemilik hanya merupakan instruksi pembayaran. Sistem tidak boleh menyatakan pembayaran telah terverifikasi hanya karena kasir menekan tombol “dibayar”.
- Integrasi otomatis memerlukan penyedia resmi, kredensial, callback/webhook, verifikasi tanda tangan, dan rekonsiliasi.
- Data rahasia gateway tidak boleh disimpan di database lokal dalam bentuk plaintext.
- Transaksi offline dapat mencatat metode pembayaran secara lokal, tetapi verifikasi gateway tidak dapat dijamin saat internet tidak tersedia.
- Sistem harus membedakan status “dicatat kasir”, “menunggu verifikasi”, “terverifikasi”, “gagal”, dan “direfund” bila relevan.
- Sistem tidak menyimpan PIN, kata sandi, atau kredensial akun DANA, SeaBank, GoPay, atau layanan keuangan pengguna.

### FR-06 — Diskon

Jenis diskon:

- Diskon nominal.
- Diskon persentase.
- Diskon per produk.
- Diskon seluruh transaksi.
- Diskon promosi berdasarkan periode, jika diaktifkan.

Aturan:

- Batas diskon dapat dikonfigurasi.
- Diskon di atas batas memerlukan otorisasi supervisor.
- Diskon tidak boleh menghasilkan nilai negatif.
- Aturan penggabungan diskon harus jelas.
- Transaksi harus menyimpan snapshot harga dan diskon saat penjualan terjadi.
- Perubahan konfigurasi promosi tidak boleh mengubah transaksi historis.

### FR-07 — Piutang

Fitur piutang harus mendukung:

- Pelanggan dengan nama dan informasi kontak opsional.
- Penjualan kredit penuh atau sebagian.
- Pembayaran cicilan.
- Sisa saldo.
- Tanggal jatuh tempo.
- Riwayat pembayaran.
- Catatan penagihan.
- Daftar piutang jatuh tempo.
- Pembatalan atau koreksi pembayaran melalui prosedur yang diaudit.

Aturan:

- Piutang hanya terbentuk dari transaksi yang memiliki saldo belum dibayar.
- Setiap pembayaran piutang harus memiliki catatan tersendiri.
- Saldo piutang tidak boleh diubah dengan mengedit angka secara langsung.
- Koreksi harus dilakukan melalui transaksi koreksi yang memiliki alasan dan jejak audit.
- Batas kredit dan peringatan pelanggan dapat dikonfigurasi per toko.

### FR-08 — Stok dan pembelian

Fitur inti:

- Saldo stok per produk dan lokasi.
- Mutasi masuk dan keluar.
- Penyesuaian stok dengan alasan.
- Stok minimum.
- Riwayat pergerakan stok.
- Retur penjualan dengan alasan.
- Retur pembelian pada tahap lanjutan.

Setiap perubahan stok harus berasal dari transaksi atau dokumen mutasi yang dapat ditelusuri.

Sistem tidak boleh memperbaiki stok dengan mengubah angka saldo tanpa mencatat alasan dan pengguna yang melakukan tindakan.

### FR-09 — Analitik dan laporan

Laporan MVP:

- Penjualan hari ini.
- Penjualan berdasarkan rentang tanggal.
- Jumlah transaksi.
- Rata-rata nilai transaksi.
- Produk terlaris berdasarkan kuantitas.
- Ringkasan metode pembayaran.
- Diskon yang diberikan.
- Stok menipis.
- Riwayat transaksi.
- Ekspor CSV.

Laporan lanjutan:

- Margin kotor berdasarkan harga modal historis.
- Performa produk dan kategori.
- Perbandingan periode.
- Piutang dan umur piutang.
- Retur dan pembatalan.
- Pergerakan stok.
- Pembelian dan pemasok.
- Perbandingan lokasi.

Laporan harus menyebutkan definisi metrik. Omzet, penerimaan kas, piutang, dan laba tidak boleh diperlakukan sebagai angka yang sama.

### FR-10 — Alert otomatis

Kategori alert:

- Stok di bawah ambang minimum.
- Produk tidak memiliki harga atau barcode yang diperlukan.
- Piutang mendekati atau melewati jatuh tempo.
- Backup terakhir terlalu lama.
- Sinkronisasi tertunda atau gagal.
- Perangkat belum tersinkron.
- Transaksi menunggu verifikasi pembayaran.
- Selisih stok memerlukan pemeriksaan.
- Aplikasi membutuhkan pembaruan atau migrasi database.

Setiap alert memiliki:

- ID unik.
- Kategori.
- Tingkat prioritas.
- Waktu kejadian.
- Status dibaca/belum dibaca.
- Aksi yang disarankan.
- Target navigasi bila tersedia.

Alert harus memiliki mekanisme deduplikasi agar pengguna tidak menerima notifikasi berulang yang tidak perlu.

Alert yang membutuhkan jaringan tidak boleh dijanjikan terkirim ketika perangkat offline. Alert lokal tetap dapat muncul berdasarkan data lokal.

### FR-11 — Backup dan pemulihan

- Backup manual tersedia pada MVP.
- Sistem menampilkan waktu backup terakhir.
- Backup harus konsisten dengan transaksi database.
- Restore harus memvalidasi versi skema dan integritas file.
- Restore tidak boleh menimpa database aktif tanpa konfirmasi dan prosedur keselamatan.
- Backup dapat disimpan ke media eksternal atau lokasi yang dipilih pengguna.
- Ekspor data bukan pengganti backup database yang konsisten.
- Backup terenkripsi dapat menjadi opsi konfigurasi untuk data sensitif.

### FR-12 — Pengguna dan audit

Peran awal:

- Owner.
- Admin.
- Supervisor.
- Cashier.
- Inventory Manager.
- Viewer/Report Only.

Hak akses mencakup:

- Membuka modul.
- Mengubah harga.
- Memberi diskon.
- Membatalkan transaksi.
- Mengubah stok.
- Melihat laporan keuangan.
- Mengelola pengguna.
- Mengonfigurasi payment gateway.
- Melakukan restore.

Semua tindakan sensitif harus dicatat bersama aktor, waktu, perangkat, jenis tindakan, dan alasan jika diperlukan.

### FR-13 — Profil fitur

Setiap toko memiliki:

- `business_type`
- `feature_profile`
- `enabled_modules`
- `ui_complexity`
- `onboarding_state`

Contoh:

- Profil Simple: menyembunyikan menu pembelian dan laporan lanjutan.
- Profil Retail: menampilkan stok, pemasok, dan pembelian.
- Profil Credit Sales: menampilkan pelanggan dan piutang.
- Profil F&B: menampilkan menu dan pesanan.

Profil tidak boleh menghapus data ketika fitur dimatikan. Pengaktifan kembali fitur harus memulihkan akses ke data yang sebelumnya tersedia.

## 7. Persyaratan nonfungsional

### NFR-01 — Offline-first

- Kasir lokal dapat melakukan penjualan tanpa koneksi internet.
- Database lokal harus bertahan setelah aplikasi ditutup atau perangkat dimulai ulang.
- Data yang belum tersinkron harus memiliki status yang terlihat.
- Sistem harus dapat melanjutkan sinkronisasi setelah koneksi pulih.
- Pengguna tidak boleh kehilangan transaksi lokal hanya karena login cloud atau API sementara gagal.

### NFR-02 — Konsistensi data

- Checkout bersifat atomik.
- Operasi yang dapat diulang harus idempoten.
- Penjualan, item, pembayaran, dan mutasi stok harus saling konsisten.
- Setiap perubahan server harus memiliki mekanisme pelacakan versi.
- Konflik sinkronisasi harus dicatat dan dapat diselesaikan.

### NFR-03 — Keamanan

- Semua komunikasi server menggunakan HTTPS.
- Password di-hash dengan mekanisme Laravel yang sesuai.
- Semua operasi backend memvalidasi otorisasi.
- Perangkat memiliki identitas dan kredensial sendiri.
- Data sensitif tidak dimasukkan ke log umum.
- Backup dan ekspor memiliki kontrol akses.
- Sistem menyediakan pencabutan akses perangkat saat online.
- Aplikasi tidak menjanjikan bahwa pencabutan perangkat dapat diterapkan secara instan ketika perangkat tetap offline.

### NFR-04 — Kemudahan penggunaan

- Bahasa antarmuka menggunakan Bahasa Indonesia yang sederhana.
- Tombol kasir mudah ditemukan dan berukuran sesuai perangkat.
- Teks tidak mengandalkan warna saja untuk menyampaikan status.
- Aksi berisiko tinggi meminta konfirmasi.
- Kesalahan menjelaskan cara memperbaikinya.
- Fitur lanjutan tidak memenuhi layar awal.
- Form mendukung keyboard, scanner, dan layar sentuh sesuai platform.

### NFR-05 — Portabilitas

- Domain logic dipisahkan dari komponen UI dan hardware.
- Akses database dilakukan melalui repository/service yang memiliki kontrak konsisten.
- Perbedaan kemampuan platform didokumentasikan.
- Fitur yang tidak didukung platform ditandai dengan fallback yang jelas.

---

# BAGIAN II — SOFTWARE ARCHITECTURE DOCUMENT (SAD)

## 8. Keputusan arsitektur utama

### ADR-001 — Modular monolith

**Keputusan:** memulai dengan Laravel modular monolith.

Alasan:

- Lebih sederhana untuk satu pengembang atau tim kecil.
- Transaksi bisnis dapat menggunakan database transaction secara konsisten.
- Deployment dan debugging lebih mudah dibandingkan microservices.
- Modul dapat dipisahkan lebih jauh bila skala produk benar-benar memerlukan.

Modul memiliki batas domain yang jelas, tetapi pada tahap awal dapat berada dalam satu aplikasi Laravel.

### ADR-002 — NativePHP sebagai jalur aplikasi native

**Keputusan:** gunakan NativePHP untuk mengevaluasi distribusi native berbasis Laravel pada Windows dan mobile.

Aplikasi web menggunakan Laravel, Blade, dan Alpine.js. Aplikasi native harus diuji terhadap pola rendering dan interaksi yang didukung versi NativePHP terpilih.

Jangan mengasumsikan semua halaman Blade/Alpine dapat dipindahkan ke mobile tanpa adaptasi. Buat lapisan UI adaptif serta kemampuan native melalui adapter.

### ADR-003 — Jangan menggabungkan NativePHP dan Capacitor secara default

Capacitor lebih cocok ketika UI utama berupa web application yang dibungkus menjadi aplikasi native. NativePHP lebih cocok ketika prioritasnya menjalankan Laravel/PHP pada perangkat native.

Keduanya merupakan alternatif arsitektur untuk sebagian kebutuhan, bukan dua dependency yang wajib dipasang bersamaan.

Jika kelak tim memilih Capacitor, UI perlu mengikuti arsitektur web/SPA yang kompatibel dan komunikasi dengan domain PHP dilakukan melalui API atau mekanisme lokal yang dirancang khusus.

### ADR-004 — Penyimpanan berbeda per lingkungan

| Lingkungan | Penyimpanan utama | Peran |
|---|---|---|
| Native Windows | SQLite lokal | Operasional offline |
| Native mobile | SQLite lokal | Operasional offline |
| Web/PWA | IndexedDB untuk cache dan antrean offline | Operasional web offline dengan batasan browser |
| Server | MySQL atau PostgreSQL | Data terpusat, sinkronisasi, multi-perangkat |

Untuk web, IndexedDB tidak identik dengan SQLite. Jangan mengasumsikan konfigurasi koneksi Laravel yang sama akan otomatis menggunakan IndexedDB. Dibutuhkan adapter penyimpanan web atau API lokal yang sesuai.

## 9. Diagram arsitektur logis

```text
                  POS UNIVERSAL
                       |
       +---------------+----------------+
       |               |                |
   Windows           Web/PWA          Mobile
   NativePHP       Laravel/Blade     NativePHP
       |            + Alpine            |
       +---------------+----------------+
                       |
              Shared Domain Contracts
                       |
   +-------------------+-------------------+
   |                   |                   |
 Sales & Checkout   Catalog & Stock    Payment & Credit
   |                   |                   |
   +-------------------+-------------------+
                       |
              Repository Interfaces
                       |
      +----------------+----------------+
      |                |                |
  SQLite Local    Web Storage       Server DB
      |                |                |
      +----------------+----------------+
                       |
              Synchronization Engine
                       |
             Laravel Sync API Server
                       |
          Central Database + Event Log
```

Diagram ini menunjukkan batas logis, bukan jaminan bahwa semua adapter penyimpanan sudah tersedia secara otomatis.

## 10. Lapisan aplikasi

### 10.1 Presentation layer

Tanggung jawab:

- Rendering halaman.
- Form input.
- Navigasi.
- Validasi awal.
- Status koneksi dan sinkronisasi.
- Interaksi keyboard, barcode, kamera, dan printer melalui adapter.

Teknologi:

- Blade.
- Alpine.js.
- CSS responsif.
- Komponen UI yang dapat digunakan ulang.
- Adapter native untuk fungsi perangkat.

Presentation layer tidak boleh menjadi tempat utama perhitungan harga, validasi stok, atau aturan piutang.

### 10.2 Application layer

Berisi use case:

- `CreateSale`
- `RecordPayment`
- `VoidSale`
- `ReceiveStock`
- `AdjustStock`
- `CreateCreditSale`
- `RecordReceivablePayment`
- `ImportProducts`
- `GenerateReport`
- `QueueSyncChanges`
- `ResolveSyncConflict`

Use case mengatur urutan operasi, otorisasi, transaksi database, dan pemanggilan domain service.

### 10.3 Domain layer

Berisi aturan inti:

- Perhitungan harga.
- Diskon.
- Pajak.
- Validasi kuantitas.
- Kebijakan stok.
- Pembentukan piutang.
- Status pembayaran.
- Status transaksi.
- Aturan pembatalan.
- Aturan konflik stok.

Domain layer tidak boleh bergantung pada komponen Blade, Alpine, API HTTP, atau driver printer.

### 10.4 Infrastructure layer

Berisi:

- Implementasi repository.
- Adapter SQLite.
- Adapter server database.
- Adapter IndexedDB untuk web.
- API client.
- Sinkronisasi.
- Queue dan job.
- Printer.
- Barcode scanner.
- Kamera.
- Payment gateway.
- Backup.
- Logging dan audit.

## 11. Strategi sinkronisasi

### 11.1 Prinsip

- Setiap perangkat menyimpan data lokal.
- Operasi lokal harus dapat diselesaikan tanpa menunggu server.
- Setiap perubahan yang perlu dikirim dimasukkan ke antrean outbox.
- Server memvalidasi setiap perubahan yang diterima.
- Perangkat melacak perubahan yang sudah dikirim dan dikonfirmasi.
- Sinkronisasi harus aman terhadap pengiriman ulang.

### 11.2 Struktur outbox

Setiap operasi sinkronisasi memiliki:

- `operation_id`
- `tenant_id`
- `device_id`
- `entity_type`
- `entity_id`
- `operation_type`
- `payload`
- `local_sequence`
- `created_at`
- `sync_status`
- `retry_count`
- `last_error`

Status:

- `pending`
- `in_flight`
- `synced`
- `conflict`
- `failed`

### 11.3 Proses sinkronisasi

1. Perangkat mendeteksi koneksi.
2. Sistem mengambil antrean yang belum tersinkron.
3. Perangkat mengirim batch operasi dengan ID idempoten.
4. Server memvalidasi identitas perangkat dan tenant.
5. Server memvalidasi aturan domain dan urutan operasi.
6. Server menyimpan operasi yang sah dalam transaksi.
7. Server mengembalikan hasil per operasi.
8. Perangkat menandai operasi yang telah dikonfirmasi.
9. Perangkat menarik perubahan server sejak cursor terakhir.
10. Perangkat menerapkan perubahan yang kompatibel.
11. Konflik dicatat dan ditampilkan kepada pengguna yang berwenang.

Pengiriman ulang setelah timeout harus aman. Perangkat tidak boleh menganggap operasi gagal hanya karena respons server tidak diterima.

### 11.4 Konflik stok

Stok tidak boleh disinkronkan dengan strategi “nilai terakhir menang”.

Contoh: dua perangkat offline masing-masing menjual barang terakhir. Kedua perangkat dapat menyelesaikan penjualan lokal, tetapi server tidak boleh diam-diam menghapus salah satu transaksi.

Kebijakan yang direkomendasikan:

- Penjualan lokal tetap tersimpan.
- Stok server dihitung berdasarkan ledger mutasi yang sah.
- Sistem mendeteksi stok negatif atau ketidaksesuaian.
- Konflik dibuat sebagai kasus yang perlu diselesaikan.
- Owner dapat mengaktifkan kebijakan stok negatif atau membatasi penjualan berdasarkan kebutuhan bisnis.
- Laporan harus membedakan stok fisik yang diperkirakan dan stok tersinkron.

Tidak mungkin menjamin stok global real-time ketika perangkat terputus dari jaringan. Produk harus menyatakan batasan tersebut secara jelas.

### 11.5 Perangkat yang lama offline

- Operasi lokal tetap dipertahankan.
- Sistem menampilkan jumlah operasi tertunda.
- Sinkronisasi menggunakan batch dengan ukuran terbatas.
- Server dapat menolak operasi yang melanggar aturan yang tidak dapat direkonsiliasi otomatis.
- Pengguna mendapat laporan konflik dan tindakan koreksi.
- Data lokal tidak boleh dihapus hanya karena operasi lama ditolak.

## 12. Model tenant dan multi-perangkat

Struktur awal mendukung satu usaha dengan kemungkinan lebih dari satu perangkat.

Setiap record bisnis yang disinkronkan harus memiliki identitas tenant atau toko.

Data minimum:

- `tenant_id`
- `location_id` jika data bergantung lokasi
- `device_id` jika record berasal dari perangkat
- `created_at`
- `updated_at`
- `version` atau cursor yang sesuai
- `deleted_at` jika menggunakan soft delete

Semua query server harus membatasi data berdasarkan tenant yang telah diotorisasi. Jangan mengandalkan `tenant_id` dari request pengguna tanpa memverifikasinya terhadap identitas dan hak akses pengguna.

## 13. Alert dan event architecture

Event domain contoh:

- `SaleCompleted`
- `SaleVoided`
- `StockThresholdReached`
- `ReceivableOverdue`
- `PaymentVerificationPending`
- `SyncFailed`
- `BackupOverdue`

Alur:

1. Use case menyelesaikan transaksi database.
2. Event dicatat setelah transaksi berhasil atau melalui transactional outbox.
3. Rule engine mengevaluasi aturan alert.
4. Sistem membuat alert dengan ID deduplikasi.
5. UI menampilkan alert lokal.
6. Jika online dan dikonfigurasi, sistem dapat mengirim notifikasi server atau push.

Jangan mengandalkan event in-memory saja untuk pekerjaan penting. Event yang harus bertahan setelah crash perlu disimpan dan diproses ulang secara aman.

## 14. Integrasi pembayaran

### Mode A — Manual

- Tunai.
- Transfer.
- QRIS statis.
- Pembayaran yang diverifikasi kasir.

Sistem mencatat metode dan status sesuai apa yang benar-benar diketahui.

### Mode B — Gateway

Komponen:

- Payment provider adapter.
- Pembuatan transaksi pembayaran.
- Penyimpanan reference ID provider.
- Callback/webhook handler.
- Verifikasi tanda tangan.
- Idempotensi callback.
- Rekonsiliasi.
- Penanganan timeout.
- Refund bila didukung.

Status pembayaran yang disarankan:

- `pending`
- `reported_by_cashier`
- `verified`
- `failed`
- `cancelled`
- `refunded`
- `partially_refunded`

Nama status harus konsisten pada API dan database. Status “reported by cashier” bukan bukti pembayaran otomatis.

### E-wallet

Integrasi DANA, GoPay, SeaBank, atau penyedia lain hanya dilakukan melalui API, gateway, atau program kemitraan yang sah dan tersedia. Jangan menjanjikan integrasi langsung ke semua layanan sebelum dokumentasi, kontrak, biaya, dan proses sertifikasi diperiksa.

## 15. Arsitektur keamanan

- Autentikasi lokal sesuai kemampuan platform.
- Autentikasi server menggunakan mekanisme yang didukung Laravel.
- Otorisasi pada setiap use case.
- Perangkat memiliki ID dan token unik.
- Token disimpan menggunakan fasilitas penyimpanan aman platform bila tersedia.
- API menggunakan HTTPS.
- Webhook memvalidasi tanda tangan dan mencegah replay.
- Password tidak disimpan dalam bentuk plaintext.
- Audit log tidak menyimpan secret.
- Data pelanggan dibatasi sesuai kebutuhan.
- Backup sensitif dapat dienkripsi.
- Perangkat yang dicabut harus ditolak pada sinkronisasi berikutnya.
- Operasi offline yang sudah sah secara lokal tetap harus dapat diaudit setelah sinkronisasi.

---

# BAGIAN III — TECHNICAL DESIGN DOCUMENT (TDD)

## 16. Struktur proyek yang direkomendasikan

```text
pos-universal/
├── app/
│   ├── Domain/
│   │   ├── Sales/
│   │   ├── Catalog/
│   │   ├── Inventory/
│   │   ├── Payments/
│   │   ├── Receivables/
│   │   ├── Reporting/
│   │   └── Sync/
│   ├── Application/
│   │   ├── Sales/
│   │   ├── Products/
│   │   ├── Inventory/
│   │   └── Sync/
│   ├── Infrastructure/
│   │   ├── Persistence/
│   │   ├── Payments/
│   │   ├── Devices/
│   │   └── Notifications/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Requests/
│   │   └── Middleware/
│   ├── Models/
│   ├── Policies/
│   ├── Jobs/
│   ├── Events/
│   └── Notifications/
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   ├── components/
│   │   ├── cashier/
│   │   ├── products/
│   │   ├── reports/
│   │   └── settings/
│   ├── js/
│   │   ├── alpine/
│   │   ├── adapters/
│   │   └── shared/
│   └── css/
├── routes/
│   ├── web.php
│   ├── api.php
│   └── console.php
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── tests/
│   ├── Unit/
│   ├── Feature/
│   ├── Integration/
│   └── Sync/
├── docs/
│   ├── PRD.md
│   ├── SAD.md
│   ├── TDD.md
│   └── ADR/
└── README.md
```

Struktur dapat disesuaikan dengan versi Laravel dan NativePHP yang dipilih. Hindari membuat terlalu banyak abstraction sebelum kebutuhan implementasinya terbukti.

## 17. Database design

### 17.1 Prinsip skema

- Gunakan ID yang cocok untuk sinkronisasi lintas perangkat, misalnya UUID.
- Gunakan foreign key.
- Gunakan transaksi database untuk operasi bisnis.
- Gunakan integer untuk nilai uang dalam satuan terkecil yang relevan, dengan definisi mata uang yang konsisten.
- Simpan snapshot harga dan diskon pada detail penjualan.
- Gunakan ledger untuk stok dan pembayaran.
- Pisahkan data transaksi dari konfigurasi yang dapat berubah.
- Gunakan soft delete untuk entitas yang perlu dipertahankan secara historis.
- Setiap perubahan skema harus melalui migration yang dapat diuji.

### 17.2 Tabel inti

**`tenants`**

- `id`
- `name`
- `business_type`
- `feature_profile`
- `currency_code`
- `timezone`
- `created_at`
- `updated_at`

**`locations`**

- `id`
- `tenant_id`
- `name`
- `address`
- `is_active`

**`users`**

- `id`
- `tenant_id`
- `name`
- `email` atau identifier login
- `password`
- `is_active`

**`roles` dan `permissions`**

- Role dan permission terpisah.
- Hubungan user-role dan role-permission.
- Izin untuk operasi sensitif harus diperiksa di server/domain.

**`devices`**

- `id`
- `tenant_id`
- `location_id`
- `device_name`
- `platform`
- `last_seen_at`
- `revoked_at`
- `app_version`
- `last_sync_cursor`

**`products`**

- `id`
- `tenant_id`
- `sku`
- `name`
- `category_id`
- `unit_id`
- `selling_price_minor`
- `cost_price_minor`
- `track_stock`
- `minimum_stock`
- `is_active`
- `version`
- `deleted_at`

**`product_barcodes`**

- `id`
- `tenant_id`
- `product_id`
- `barcode`
- `barcode_type`

**`sales`**

- `id`
- `tenant_id`
- `location_id`
- `device_id`
- `receipt_number`
- `client_request_id`
- `cashier_id`
- `status`
- `subtotal_minor`
- `discount_minor`
- `tax_minor`
- `total_minor`
- `currency_code`
- `sold_at`
- `created_at`

Unique constraint yang diperlukan mencakup lingkup transaksi idempoten, misalnya `(tenant_id, device_id, client_request_id)`, serta nomor struk sesuai kebijakan penomoran.

**`sale_items`**

- `id`
- `sale_id`
- `product_id`
- `product_name_snapshot`
- `sku_snapshot`
- `quantity`
- `unit_price_minor`
- `discount_minor`
- `tax_minor`
- `line_total_minor`
- `cost_price_snapshot_minor` jika tersedia

**`payments`**

- `id`
- `tenant_id`
- `sale_id`
- `method`
- `amount_minor`
- `status`
- `provider_reference`
- `paid_at`
- `created_at`

**`stock_balances`**

- `tenant_id`
- `location_id`
- `product_id`
- `quantity`
- `version`

**`stock_movements`**

- `id`
- `tenant_id`
- `location_id`
- `product_id`
- `movement_type`
- `quantity_delta`
- `source_type`
- `source_id`
- `actor_id`
- `reason`
- `created_at`

**`customers`**

- `id`
- `tenant_id`
- `name`
- `phone`
- `credit_limit_minor`
- `is_active`

**`receivables`**

- `id`
- `tenant_id`
- `customer_id`
- `sale_id`
- `original_amount_minor`
- `due_at`
- `status`

**`receivable_payments`**

- `id`
- `receivable_id`
- `payment_id`
- `amount_minor`
- `created_at`

**`alerts`**

- `id`
- `tenant_id`
- `device_id` jika lokal
- `alert_type`
- `deduplication_key`
- `severity`
- `payload`
- `read_at`
- `created_at`

**`sync_outbox`**

- `operation_id`
- `tenant_id`
- `device_id`
- `entity_type`
- `entity_id`
- `operation_type`
- `payload`
- `local_sequence`
- `sync_status`
- `retry_count`
- `last_error`
- `created_at`

**`audit_logs`**

- `id`
- `tenant_id`
- `actor_id`
- `device_id`
- `action`
- `entity_type`
- `entity_id`
- `reason`
- `metadata`
- `created_at`

**`settings` dan `feature_flags`**

- Menyimpan konfigurasi per tenant.
- Menyimpan status fitur.
- Menyimpan versi konfigurasi.
- Perubahan harus memiliki aturan sinkronisasi yang jelas.

### 17.3 Konsistensi stok

Saldo stok adalah nilai operasional yang diperbarui secara atomik. Ledger mutasi adalah jejak perubahan yang menjadi dasar audit dan rekonsiliasi.

Dalam satu transaksi checkout:

1. Periksa request ID untuk idempotensi.
2. Validasi produk, harga, diskon, dan stok.
3. Buat record penjualan.
4. Buat item penjualan.
5. Buat record pembayaran.
6. Buat piutang jika masih ada saldo belum dibayar.
7. Buat mutasi stok.
8. Perbarui saldo stok.
9. Buat operasi outbox untuk sinkronisasi.
10. Commit.

Jika salah satu langkah wajib gagal, seluruh transaksi harus rollback.

Transaksi harus selesai sebelum pencetakan struk dimulai. Kegagalan printer tidak boleh membatalkan penjualan yang sudah committed.

### 17.4 Retur dan void

- Transaksi selesai tidak dihapus permanen.
- Pembatalan memerlukan alasan dan izin.
- Pembatalan membuat record koreksi dan mutasi balik.
- Pengembalian stok hanya dilakukan sesuai kebijakan retur.
- Koreksi pembayaran harus tercatat.
- Operasi void harus idempoten agar tidak mengembalikan stok dua kali.

## 18. API design

API server menggunakan versi, misalnya `/api/v1`.

### Endpoint inti

- `POST /api/v1/auth/login`
- `POST /api/v1/devices/register`
- `GET /api/v1/bootstrap`
- `GET /api/v1/products`
- `POST /api/v1/sales`
- `GET /api/v1/sales/{id}`
- `POST /api/v1/sales/{id}/void`
- `POST /api/v1/receivables/{id}/payments`
- `GET /api/v1/reports/sales`
- `POST /api/v1/sync/push`
- `GET /api/v1/sync/pull?cursor=...`
- `POST /api/v1/payments/webhooks/{provider}`

Endpoint yang mengubah data harus melakukan validasi, otorisasi, dan idempotensi sesuai jenis operasi.

### Contoh kontrak checkout

Request:

```json
{
  "client_request_id": "UUID",
  "device_id": "UUID",
  "location_id": "UUID",
  "items": [
    {
      "product_id": "UUID",
      "quantity": 2
    }
  ],
  "discount": {
    "type": "fixed",
    "value": 1000
  },
  "payments": [
    {
      "method": "cash",
      "amount_minor": 25000
    }
  ]
}
```

Respons harus menyertakan:

- ID transaksi.
- Nomor struk.
- Total final.
- Rincian pembayaran.
- Sisa piutang jika ada.
- Status sinkronisasi.
- Informasi error validasi bila gagal.

Nilai harga yang dikirim client tidak boleh dipercaya sebagai sumber final. Server atau domain service harus menghitung ulang menggunakan aturan dan harga yang sah.

## 19. UI/UX design

### 19.1 Navigasi sederhana

Menu utama profil Simple:

- Kasir
- Produk
- Riwayat
- Laporan
- Pengaturan

Menu tambahan hanya muncul ketika diaktifkan dan diizinkan:

- Stok
- Pembelian
- Pelanggan
- Piutang
- Promosi
- Analitik lanjutan
- Sinkronisasi
- Audit

### 19.2 Layout kasir desktop

- Area pencarian atau barcode.
- Daftar item keranjang.
- Kuantitas dan diskon.
- Total transaksi.
- Metode pembayaran.
- Tombol bayar yang menonjol.
- Indikator koneksi dan sinkronisasi yang tidak mengganggu.
- Akses cepat ke transaksi terakhir.

Shortcut awal:

- `F1`: fokus pencarian produk.
- `F2`: buka pembayaran.
- `F3`: ubah kuantitas.
- `Delete`: hapus item yang dipilih, sesuai izin.
- `Esc`: tutup dialog yang aman.

Shortcut harus diuji agar tidak bertabrakan dengan shortcut browser atau native runtime.

### 19.3 Layout mobile

- Tombol produk dan pembayaran yang mudah disentuh.
- Form pendek.
- Navigasi bawah untuk fungsi utama.
- Dialog konfirmasi yang jelas.
- Status offline terlihat.
- Kamera dan scanner hanya meminta izin ketika dibutuhkan.
- Jangan menampilkan tabel desktop yang diperkecil sebagai satu-satunya UI mobile.

### 19.4 Prinsip untuk pengguna lanjut usia

- Ukuran teks dapat diperbesar.
- Kontras memadai.
- Bahasa sehari-hari.
- Ikon selalu disertai label pada tindakan penting.
- Tidak mengandalkan gesture tersembunyi.
- Tombol berisiko tinggi diberi konfirmasi.
- Tidak terlalu banyak menu pada layar awal.
- Tampilkan langkah berikutnya setelah kesalahan.
- Sediakan mode tampilan sederhana.

## 20. Printer, barcode, kamera, dan hardware

### Barcode scanner

Prioritaskan scanner USB yang berperilaku seperti keyboard pada desktop. Ini mengurangi ketergantungan pada driver dan SDK khusus.

### Printer

- Gunakan jalur pencetakan yang didukung OS atau runtime.
- Uji printer thermal 58 mm dan 80 mm berdasarkan model nyata.
- Sediakan struk yang dapat disimpan atau dicetak ulang.
- Printer tidak boleh menjadi syarat untuk menyimpan transaksi.
- Integrasi ESC/POS langsung hanya ditambahkan jika kebutuhan perangkat mengharuskannya.

### Kamera

- Kamera native untuk mobile jika runtime menyediakan adapter yang stabil.
- Kamera browser dengan izin pengguna untuk web.
- Jika gagal, tawarkan input manual.

### Cash drawer

Dapat ditambahkan setelah printer dan alur checkout stabil. Jangan menganggap semua cash drawer dapat diakses tanpa konfigurasi khusus.

## 21. Backup, restore, dan migrasi

- Setiap rilis memiliki versi skema database.
- Migration harus diuji terhadap database kosong dan database lama.
- Backup dibuat dari snapshot yang konsisten.
- Backup tidak boleh menyalin database aktif secara sembarangan ketika transaksi sedang berlangsung.
- Restore harus memeriksa integritas database.
- Migrasi gagal tidak boleh menghapus database asli.
- Pengguna diberi instruksi dan status jika migrasi perlu tindakan manual.

## 22. Pengujian

### Unit tests

- Perhitungan subtotal.
- Diskon persentase dan nominal.
- Pembulatan uang.
- Perhitungan pajak.
- Saldo pembayaran.
- Saldo piutang.
- Validasi stok.
- Kebijakan void.
- Aturan profil fitur.

### Feature tests

- Login dan otorisasi.
- CRUD produk.
- Checkout.
- Pembayaran campuran.
- Penjualan kredit.
- Retur.
- Laporan.
- Alert stok.
- Impor CSV.
- Backup dan restore.

### Integration tests

- SQLite pada runtime native.
- IndexedDB adapter untuk web.
- Sinkronisasi ke server.
- Webhook pembayaran.
- Scanner keyboard.
- Printer.
- Kamera.
- Migrasi database.

### Pengujian kegagalan wajib

- Klik bayar dua kali.
- Respons server hilang setelah transaksi tersimpan.
- Aplikasi ditutup ketika operasi masih menunggu.
- Koneksi terputus saat sinkronisasi.
- Dua perangkat menjual produk yang sama ketika offline.
- Printer gagal setelah checkout berhasil.
- Backup dipulihkan ke database baru.
- Pengiriman webhook duplikat.
- Void dipanggil dua kali.
- Database lokal tidak dapat dibuka.
- Migrasi gagal pada database pengguna lama.

## 23. Observability dan diagnostik

Aplikasi perlu menampilkan informasi yang dapat membantu pengguna dan tim dukungan:

- Versi aplikasi.
- Versi skema database.
- Status koneksi.
- Waktu sinkronisasi terakhir.
- Jumlah operasi tertunda.
- Waktu backup terakhir.
- Error integrasi perangkat yang dapat ditindaklanjuti.

Log teknis harus dapat diekspor dengan persetujuan pengguna. Data sensitif, token, kredensial gateway, dan informasi pembayaran yang tidak diperlukan tidak boleh masuk ke log.

---

# BAGIAN IV — IMPLEMENTATION ROADMAP

## 24. Tahapan pengembangan

### Fase 0 — Validasi teknis

Tujuan: membuktikan bahwa kombinasi Laravel, Alpine.js, NativePHP, SQLite, dan perangkat target dapat bekerja sebelum fitur bisnis dibuat banyak.

Deliverables:

- Aplikasi Laravel dapat dijalankan di lingkungan native yang dipilih.
- Satu migration berjalan pada SQLite lokal.
- CRUD produk sederhana.
- Aplikasi tetap dapat membuka data setelah restart.
- Printer dan scanner diuji pada perangkat nyata.
- Build Windows berhasil.
- Jalur build Android diuji.
- Dukungan web/PWA dan batasan offline diverifikasi.

**Exit criteria:** tidak ada ketergantungan yang belum terpecahkan terkait runtime, database, build, atau hardware utama.

### Fase 1 — Fondasi bisnis

- Struktur domain.
- Tenant dan perangkat.
- Produk dan kategori.
- Database migration.
- Repository dan service.
- Pengguna dan permission.
- Audit log dasar.
- Profil fitur.

### Fase 2 — Kasir offline

- Keranjang.
- Checkout atomik.
- Pembayaran tunai.
- Diskon.
- Stok dan mutasi.
- Nomor struk.
- Idempotensi.
- Riwayat transaksi.
- Cetak ulang struk.

### Fase 3 — Operasional toko

- Impor produk.
- Laporan harian.
- Stok minimum.
- Backup dan restore.
- Profil Simple/Retail.
- Piutang dasar.
- Alert lokal.

### Fase 4 — Sinkronisasi

- Registrasi perangkat.
- Outbox lokal.
- API push/pull.
- Cursor sinkronisasi.
- Retry dan idempotensi.
- Deteksi konflik.
- Diagnostik sinkronisasi.

### Fase 5 — Payment dan fitur lanjutan

- QRIS statis.
- Adapter payment gateway.
- Webhook dan rekonsiliasi.
- Barcode kamera.
- OCR sebagai bantuan input.
- Laporan lanjutan.
- Profil F&B dan Credit Sales.

### Fase 6 — Pilot dan komersialisasi

- Uji di beberapa toko.
- Observasi penggunaan.
- Perbaikan UI.
- Dokumentasi instalasi.
- Mekanisme update.
- Kebijakan lisensi.
- Backup dan dukungan pelanggan.
- Uji pemulihan dari kerusakan perangkat.

## 25. Backlog awal

### Sprint 1 — Proof of concept

1. Buat repository Git.
2. Tentukan versi Laravel dan NativePHP yang kompatibel.
3. Buat aplikasi Laravel minimal.
4. Uji build Windows.
5. Uji penyimpanan SQLite.
6. Uji migration dan restart.
7. Buat halaman produk menggunakan Blade dan Alpine.
8. Uji scanner USB.
9. Uji printer thermal.
10. Catat batasan runtime dan paket yang diperlukan.

### Sprint 2 — Produk dan checkout

1. Buat migration produk, penjualan, item, pembayaran, saldo stok, dan mutasi stok.
2. Buat domain service untuk harga dan diskon.
3. Buat repository dan use case checkout.
4. Implementasikan idempotensi.
5. Implementasikan transaksi atomik.
6. Implementasikan pembayaran tunai.
7. Implementasikan pengurangan stok.
8. Buat UI kasir.
9. Buat riwayat penjualan.
10. Tambahkan unit dan feature tests.

### Sprint 3 — Laporan dan operasional

1. Impor CSV dengan validasi.
2. Laporan harian.
3. Alert stok minimum.
4. Backup dan restore.
5. Peran pengguna.
6. Audit tindakan sensitif.
7. Profil Simple dan Retail.
8. Uji kegagalan printer.
9. Uji restart dan recovery.
10. Dokumentasikan prosedur dukungan.

### Sprint 4 — Offline sync foundation

1. Identitas perangkat.
2. Outbox.
3. API push/pull.
4. Cursor sinkronisasi.
5. Retry dengan backoff.
6. Idempotensi server.
7. Konflik stok.
8. Status sinkronisasi UI.
9. Uji koneksi putus.
10. Uji dua perangkat.

Sprint di atas merupakan urutan implementasi awal, bukan estimasi waktu yang dijamin. Durasi perlu disesuaikan setelah proof of concept NativePHP selesai.

---

# BAGIAN V — ACCEPTANCE CRITERIA DAN RELEASE GATE

## 26. Kriteria penerimaan MVP

MVP hanya dapat dinyatakan siap pilot apabila:

- Produk dapat dibuat dan dipertahankan setelah aplikasi ditutup.
- Checkout berhasil tanpa internet pada perangkat native yang didukung.
- Checkout yang gagal tidak menghasilkan penjualan parsial.
- Request checkout yang diulang tidak menggandakan penjualan.
- Penjualan yang tersimpan tetap ada ketika printer gagal.
- Perubahan stok memiliki catatan mutasi.
- Void tidak mengembalikan stok dua kali.
- Laporan penjualan cocok dengan transaksi sumber.
- Backup dapat dipulihkan ke database baru.
- Pengguna tidak dapat mengakses operasi di luar izinnya.
- Operasi sinkronisasi dapat diulang tanpa duplikasi.
- Konflik stok tidak disembunyikan.
- QRIS statis tidak ditandai sebagai pembayaran terverifikasi secara otomatis.
- Menu yang tidak diaktifkan tidak membingungkan pengguna profil Simple.
- Build diuji pada perangkat dan OS yang tercantum dalam matriks dukungan.

## 27. Risiko dan mitigasi

| Risiko | Mitigasi |
|---|---|
| NativePHP belum cocok dengan seluruh pola UI yang direncanakan | Proof of concept sebelum implementasi besar |
| Perbedaan database native dan web | Adapter penyimpanan dengan kontrak eksplisit |
| Konflik stok ketika offline | Ledger, idempotensi, kebijakan konflik, dan rekonsiliasi |
| Printer tidak kompatibel | Daftar perangkat teruji dan fallback cetak |
| Integrasi e-wallet tidak tersedia | Payment provider adapter dan verifikasi kontrak/API |
| OCR salah membaca harga | Konfirmasi pengguna sebelum menyimpan |
| Database rusak | Backup konsisten, restore test, dan diagnostik |
| Fitur terlalu rumit | Profil fitur dan UI sederhana |
| Update mengubah data pengguna | Migration versioning dan backup sebelum upgrade |
| Perangkat lama offline | Antrean persisten dan penanganan konflik yang dapat diaudit |

## 28. Keputusan yang masih perlu divalidasi

Sebelum produksi, tim perlu menetapkan:

1. Versi Laravel dan NativePHP yang kompatibel.
2. Target Windows minimum dan arsitektur build.
3. Versi Android minimum dan target iOS.
4. Pola penyimpanan offline web yang dipilih.
5. Printer dan scanner yang akan masuk daftar dukungan.
6. Kebijakan stok negatif.
7. Kebijakan nomor struk per perangkat dan lokasi.
8. Penyedia payment gateway dan persyaratan integrasinya.
9. Batas data yang dapat diakses setiap peran.
10. Model lisensi, backup, dan dukungan produk.

---

# KESIMPULAN

POS Universal sebaiknya dibangun sebagai satu produk modular dengan domain logic bersama, bukan tiga aplikasi terpisah yang berkembang sendiri-sendiri.

Urutan yang paling aman adalah membuktikan runtime native dan SQLite terlebih dahulu, menyelesaikan transaksi kasir offline, kemudian membangun backup, laporan, dan sinkronisasi. Integrasi pembayaran, OCR, dan fitur lanjutan ditambahkan setelah fondasi transaksi dapat dipercaya.

**Prinsip release:** jangan menambah banyak fitur sebelum transaksi, stok, pembayaran, backup, dan pemulihan data lulus pengujian.
