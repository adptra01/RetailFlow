<?php

namespace App\Enums;

/**
 * Peran awal FR-12: Owner, Admin, Supervisor, Cashier, Inventory Manager,
 * Viewer/Report Only.
 *
 * Matriks izin di sini adalah DEFAULT yang bisa disesuaikan via pengelolaan
 * pengguna; `DefaultRolesSeeder` menerapkannya idempoten. Otorisasi selalu
 * diperiksa lewat izin (`Permission`), bukan lewat nama peran — peran hanya
 * kumpulan izin awal.
 */
enum Role: string
{
    case Owner = 'owner';

    case Admin = 'admin';

    case Supervisor = 'supervisor';

    case Cashier = 'cashier';

    case InventoryManager = 'inventory_manager';

    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Supervisor => 'Supervisor',
            self::Cashier => 'Kasir',
            self::InventoryManager => 'Manajer Inventori',
            self::Viewer => 'Viewer / Laporan Saja',
        };
    }

    /**
     * Matriks izin default per peran (FR-12).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => [
                Permission::AccessModule,
                Permission::UpdatePrice,
                Permission::ApplyDiscount,
                Permission::VoidTransaction,
                Permission::ReturnTransaction,
                Permission::AdjustStock,
                Permission::ViewFinancialReports,
                Permission::ManageUsers,
                Permission::ConfigurePaymentGateway,
            ],
            self::Supervisor => [
                Permission::AccessModule,
                Permission::ApplyDiscount,
                Permission::VoidTransaction,
                Permission::ReturnTransaction,
                Permission::AdjustStock,
                Permission::ViewFinancialReports,
            ],
            self::Cashier => [
                Permission::AccessModule,
                Permission::ApplyDiscount,
                Permission::VoidTransaction,
                Permission::ReturnTransaction,
            ],
            self::InventoryManager => [
                Permission::AccessModule,
                Permission::AdjustStock,
            ],
            self::Viewer => [
                Permission::AccessModule,
                Permission::ViewFinancialReports,
            ],
        };
    }

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }
}
