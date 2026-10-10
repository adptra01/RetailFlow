<?php

namespace App\Enums;

/**
 * Katalog jenis tindakan audit (FR-12: "Semua tindakan sensitif harus
 * dicatat bersama aktor, waktu, perangkat, jenis tindakan, dan alasan
 * jika diperlukan").
 *
 * Satu-satunya sumber nama aksi: gunakan konstanta ini (atau
 * `AuditRecorder`) agar jejak audit konsisten dan mudah difilter.
 */
enum AuditAction: string
{
    // Penjualan (CreateSale) — sudah terekam sejak Fase 2.
    case SaleCreated = 'sale.created';

    // Pembayaran piutang (RecordReceivablePayment).
    case ReceivablePayment = 'receivable.payment';

    // Pengelolaan pengguna (ManageUser, izin `user.manage`).
    case UserCreated = 'user.created';

    case UserUpdated = 'user.updated';

    case UserDeactivated = 'user.deactivated';

    case UserReactivated = 'user.reactivated';

    case UserRoleChanged = 'user.role_changed';

    // Pulihkan backup (BackupManager::restore, izin `backup.restore`).
    case BackupRestored = 'backup.restored';

    // Mengakses laporan keuangan (SalesReport, izin `report.financial.view`).
    case FinancialReportViewed = 'report.financial.viewed';
}