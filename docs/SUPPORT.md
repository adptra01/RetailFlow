# RetailFlow — Prosedur Dukungan (Support Runbook)

> Dokumen ini melengkapi Sprint 3 item 10 ("Dokumentasikan prosedur dukungan")
> dan §23 (Observability & diagnostik) pada `.ai/plan/PRD, TDD, SAD.md`.
> Semua perintah dijalankan lewat CLI; jangan pernah meminta kredensial
> pelanggan atau menyalin data pembayaran ke tiket.

---

## 1. Informasi diagnostik yang harus dikumpulkan

Kumpulkan ini lebih dulu sebelum eskalasi (§23):

| Info | Sumber |
| --- | --- |
| Versi aplikasi | `php artisan about` · `config('pos.version')` |
| Versi skema database | `config('pos.schema_version')` (dipakai validasi restore, FR-11) |
| Status koneksi database | `php artisan about` · `pos:backup:status` |
| Waktu backup terakhir | `php artisan pos:backup:status` |
| Versi framework/runtime | `php artisan about` (PHP, Laravel) |
| Kesehatan database | `BackupManager::isHealthy()` (uji `pos:backup:status`) |
| Alert lokal aktif | `php artisan pos:alerts:generate` (FR-10) |
| Peran/izin pengguna | `php artisan permission:show` |

> **Sinkronisasi terakhir / operasi tertunda** (rencana §23) belum tersedia —
> menyusul pada Fase 4 (offline sync). Catat sebagai *belum dibangun* di tiket.

**Privasi log (§23):** ekspor log hanya dengan persetujuan pengguna. Data
sensitif, token, kredensial gateway, dan informasi pembayaran tidak boleh
masuk tangkapan layar/tiket.

---

## 2. Prosedur pemulihan (recovery)

### 2.1 Aplikasi/data setelah restart

Data operasional bersifat persisten (tersimpan di database, bukan memori
proses). Setelah restart, saldo stok harus tetap konsisten dengan ledger
mutasi. Diuji oleh `tests/Feature/Recovery/DatabaseRestartTest.php`.

**Tindakan:** restart layanan; bila data tidak muncul, cek koneksi database
yang benar (env `DB_*`) lalu jalankan `php artisan about`.

### 2.2 Checkout gagal di tengah jalan

Transaksi ditulis secara **atomik**: kegagalan tidak meninggalkan data
parsial (tanpa penjualan/item/ledger separuh). Operasi aman diulang memakai
`client_request_id` yang sama (idempoten). Diuji oleh
`tests/Feature/Recovery/RecoveryTest.php` dan `tests/Feature/Sales/CreateSaleTest.php`.

**Tindakan:** minta kasir mengirim ulang pembayaran. Jangan membuat transaksi
manual ganda.

### 2.3 Printer gagal setelah checkout berhasil

Penjualan yang sudah tersimpan **tidak boleh dibatalkan** hanya karena printer
gagal (§17.3, §20). Struk dapat dicetak ulang. Kegagalan tercatat di log
(`Log::warning`) dan audit `receipt.print_failed`. Port printer bersifat
opsional (adaptor default no-op).

**Tindakan:** periksa koneksi/pengaturan printer, lalu cetak ulang struk.
Transaksi tetap sah.

### 2.4 Pemulihan dari backup (FR-11)

Backup dipulihkan **hanya** dengan validasi + konfirmasi eksplisit, dan
membuat snapshot keselamatan otomatis sebelum menimpa.

```bash
php artisan pos:backup:status                       # lihat backup terakhir
php artisan pos:backup:create                       # buat backup manual
php artisan pos:backup:create --path=/media/external # ke media eksternal
php artisan pos:backup:restore <file> --force       # pulihkan (wajib konfirmasi)
```

Aturan:
- Backup terenkripsi bila `POS_BACKUP_ENCRYPT=true` (kunci dari `APP_KEY`).
- Berkas rusak/dimodifikasi, tanpa metadata, atau `schema_version` lebih baru
  dari aplikasi **ditolak**.
- Restore memerlukan izin `backup.restore` (FR-12). **Admin tidak memiliki**
  izin ini — hanya Owner.

### 2.5 Restart & recovery terjadwal

- Jalankan backup berkala (`pos:backup:create`) dan pantau alert `BackupOverdue`
  (`pos:alerts:generate`, ambang `POS_BACKUP_MAX_AGE_DAYS`).
- Uji pemulihan dari kerusakan perangkat minimal sekali per siklus rilis.

---

## 3. Peran, izin, dan audit (FR-12)

- Otorisasi berbasis **izin**, bukan nama peran. Katalog: `php artisan permission:show`.
- Peran awal (idempoten): `php artisan pos:roles:seed`
  (Owner, Admin, Supervisor, Kasir, Manajer Inventori, Viewer).
- Tindakan sensitif dicatat di `audit_logs` (aktor, waktu, entitas, alasan).
  Audit bersifat *append-only*.

## 4. Alert lokal (FR-10)

```bash
php artisan pos:alerts:generate   # stok menipis, data produk belum lengkap,
                                   # piutang jatuh tempo, backup terlalu lama
```

## 5. Eskalasi

Sertakan pada tiket: versi aplikasi & skema, langkah reproduksi, waktu
kejadian, entri log relevan (tanpa data sensitif), dan langkah pemulihan yang
sudah dicoba (bagian 2). Untuk masalah data, lampirkan hasil
`pos:backup:status` dan konfirmasi backup terakhir.

## 6. Belum dibangun (jangan dijanjikan)

- Sinkronisasi antar-perangkat & operasi tertunda (Fase 4).
- Handshake perangkat, barcode kamera, cash drawer, driver thermal nyata.
- Halaman diagnostik in-app (saat ini lewat CLI).
