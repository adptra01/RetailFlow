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

    // Pembatalan penjualan terkontrol (VoidSale, izin `transaction.void`, §17.4).
    case SaleVoided = 'sale.voided';

    // Retur penjualan — CreateSalesReturn (FR-08, §17.4).
    case SaleReturned = 'sale.returned';

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

    // Konfigurasi profil fitur (FR-13, ConfigureFeatureProfile).
    case FeatureProfileChanged = 'feature_profile.changed';

    case ModuleEnabled = 'module.enabled';

    case ModuleDisabled = 'module.disabled';

    case TenantConfigured = 'tenant.configured';

    // Konfigurasi pembayaran QRIS statis (FR-05, izin payment_gateway.configure).
    case PaymentGatewayConfigured = 'payment_gateway.configured';

    // Mode B — Gateway (plan §14): pembuatan transaksi pembayaran di penyedia.
    case PaymentChargeCreated = 'payment.charge_created';

    // Callback/webhook yang sah dan mengubah status pembayaran (§14/§15).
    case PaymentWebhookApplied = 'payment.webhook_applied';

    // Callback yang tanda tangannya tidak sah — tidak boleh mengubah apa pun (§15).
    case PaymentWebhookRejected = 'payment.webhook_rejected';

    // Callback sah untuk reference ID provider yang tidak kita kenal (§14).
    case PaymentWebhookUnmatched = 'payment.webhook_unmatched';

    // Rekonsiliasi (plan §14): status lokal diselaraskan dengan status penyedia.
    case PaymentReconciled = 'payment.reconciled';

    // Kegagalan pencetakan struk (PrintReceipt, §20) — penjualan tetap sah.
    case ReceiptPrintFailed = 'receipt.print_failed';

    // Identitas perangkat (Sprint 4, NFR-03, §15).
    case DeviceRegistered = 'device.registered';

    case DeviceRevoked = 'device.revoked';

    // Pembelian — CreatePurchaseOrder.
    case PurchaseOrderCreated = 'purchase_order.created';

    // Penerimaan barang — ReceiveGoods.
    case GoodsReceived = 'goods.received';

    // Retur pembelian — CreatePurchaseReturn.
    case PurchaseReturnCreated = 'purchase_return.created';

    // Pembayaran supplier — RecordSupplierPayment.
    case SupplierPaymentRecorded = 'supplier_payment.recorded';

    // Resep & bahan baku (F&B) — UpdateRecipe.
    case RecipeUpdated = 'recipe.updated';

    // Transisi status pesanan — AdvanceOrderStatus (dapat menyesuaikan stok bahan).
    case OrderStatusChanged = 'order.status_changed';
}
