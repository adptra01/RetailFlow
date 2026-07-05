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
