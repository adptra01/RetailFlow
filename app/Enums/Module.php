<?php

namespace App\Enums;

/**
 * Katalog modul fitur (FR-13). Nilai enum = identifier yang disimpan di
 * `tenants.enabled_modules` dan dipakai matriks `FeatureProfile::modules()`.
 *
 * Modul hanya mengatur VISIBILITAS menu/akses UI — bukan otorisasi. Izin
 * (FR-12) selalu ditegakkan terpisah. Mematikan modul tidak pernah menghapus
 * data; mengaktifkan kembali memulihkan akses ke data yang sama.
 */
enum Module: string
{
    case Cashier = 'cashier';

    case Products = 'products';

    case History = 'history';

    case Stock = 'stock';

    case Purchases = 'purchases';

    case Suppliers = 'suppliers';

    case Customers = 'customers';

    case Receivables = 'receivables';

    case Promotions = 'promotions';

    case Reports = 'reports';

    case ReportsAdvanced = 'reports_advanced';

    case Menu = 'menu';

    case Orders = 'orders';

    case Audit = 'audit';

    case Sync = 'sync';

    public function label(): string
    {
        return match ($this) {
            self::Cashier => 'Kasir',
            self::Products => 'Produk',
            self::History => 'Riwayat transaksi',
            self::Stock => 'Stok',
            self::Purchases => 'Pembelian',
            self::Suppliers => 'Pemasok',
            self::Customers => 'Pelanggan',
            self::Receivables => 'Piutang',
            self::Promotions => 'Promosi',
            self::Reports => 'Laporan',
            self::ReportsAdvanced => 'Laporan lanjutan',
            self::Menu => 'Menu',
            self::Orders => 'Pesanan',
            self::Audit => 'Audit',
            self::Sync => 'Sinkronisasi',
        };
    }
}
