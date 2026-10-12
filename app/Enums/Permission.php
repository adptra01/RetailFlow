<?php

namespace App\Enums;

/**
 * Katalog izin FR-12 (hak akses operasi sensitif).
 *
 * Nilai enum = nama izin yang dipakai spatie/laravel-permission. Sumber
 * tunggal kebenaran: seeder `DefaultRolesSeeder` membangun peran dari
 * `Role::permissions()` (matriks), bukan dari daftar izin terpisah.
 */
enum Permission: string
{
    // Membuka modul.
    case AccessModule = 'access.module';

    // Mengubah harga.
    case UpdatePrice = 'price.update';

    // Memberi diskon.
    case ApplyDiscount = 'discount.apply';

    // Membatalkan transaksi.
    case VoidTransaction = 'transaction.void';

    // Melakukan retur penjualan (FR-08, §17.4).
    case ReturnTransaction = 'transaction.return';

    // Mengubah stok.
    case AdjustStock = 'stock.adjust';

    // Melihat laporan keuangan.
    case ViewFinancialReports = 'report.financial.view';

    // Mengelola pengguna.
    case ManageUsers = 'user.manage';

    // Mengonfigurasi payment gateway.
    case ConfigurePaymentGateway = 'payment_gateway.configure';

    // Melakukan restore (FR-12; dipakai `BackupManager::restore` dan
    // `pos:backup:restore` bila aktor diberikan).
    case RestoreBackup = 'backup.restore';

    public function label(): string
    {
        return match ($this) {
            self::AccessModule => 'Membuka modul',
            self::UpdatePrice => 'Mengubah harga',
            self::ApplyDiscount => 'Memberi diskon',
            self::VoidTransaction => 'Membatalkan transaksi',
            self::ReturnTransaction => 'Melakukan retur penjualan',
            self::AdjustStock => 'Mengubah stok',
            self::ViewFinancialReports => 'Melihat laporan keuangan',
            self::ManageUsers => 'Mengelola pengguna',
            self::ConfigurePaymentGateway => 'Mengonfigurasi payment gateway',
            self::RestoreBackup => 'Melakukan restore',
        };
    }
}
