<?php

namespace App\Actions\Payments;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\Payments\InvalidQrisPayload;
use App\Exceptions\Tenants\TenantNotFound;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Payments\QrisPayload;

/**
 * Konfigurasi QRIS statis tenant (FR-05, use case "settings" §10.4).
 *
 * Aturan penting:
 *  - hanya pemegang izin `payment_gateway.configure` (Owner/Admin) yang boleh
 *    mengubahnya (FR-12);
 *  - payload divalidasi sebagai QRIS statis Indonesia yang utuh — QRIS dinamis
 *    ditolak (irisan ini hanya menangani statis);
 *  - payload bukan rahasia, tetapi jejak audit menyimpan sidik jari
 *    (sha256) + nama merchant, bukan payload penuh;
 *  - QRIS statis TIDAK memverifikasi pembayaran apa pun: sistem hanya
 *    memajangnya sebagai instruksi pembayaran (FR-05).
 */
class ConfigureQris
{
    private readonly AuditRecorder $audit;

    public function __construct(
        private readonly string $tenantId,
        ?AuditRecorder $audit = null,
    ) {
        $this->audit = $audit ?? new AuditRecorder($this->tenantId);
    }

    /**
     * Simpan/perbarui QRIS statis. Nama merchant diambil dari payload (tag 59)
     * bila pemanggil tidak memberi nama eksplisit.
     *
     * @throws InvalidQrisPayload|TenantNotFound
     */
    public function configure(User $actor, string $payload, ?string $merchantName = null, ?string $reason = null): Tenant
    {
        ActionAuthorizer::assert($actor, Permission::ConfigurePaymentGateway);

        $parsed = QrisPayload::parse($payload);
        $tenant = $this->tenant();

        $name = $this->cleanName($merchantName) ?? $parsed->merchantName();

        $tenant->update([
            'qris_payload' => $parsed->raw(),
            'qris_merchant_name' => $name,
        ]);

        $this->audit->record(
            AuditAction::PaymentGatewayConfigured,
            $actor,
            entityType: 'tenant',
            entityId: $tenant->id,
            reason: $reason,
            metadata: [
                'channel' => 'qris_static',
                'merchant_name' => $name,
                'fingerprint' => $parsed->fingerprint(),
                'city' => $parsed->merchantCity(),
            ],
        );

        return $tenant->refresh();
    }

    /**
     * Hapus konfigurasi QRIS (tidak menyentuh transaksi mana pun).
     *
     * @throws TenantNotFound
     */
    public function clear(User $actor, ?string $reason = null): Tenant
    {
        ActionAuthorizer::assert($actor, Permission::ConfigurePaymentGateway);

        $tenant = $this->tenant();

        $tenant->update([
            'qris_payload' => null,
            'qris_merchant_name' => null,
        ]);

        $this->audit->record(
            AuditAction::PaymentGatewayConfigured,
            $actor,
            entityType: 'tenant',
            entityId: $tenant->id,
            reason: $reason,
            metadata: ['channel' => 'qris_static', 'cleared' => true],
        );

        return $tenant->refresh();
    }

    private function cleanName(?string $name): ?string
    {
        $name = $name === null ? null : trim($name);

        return $name === '' ? null : $name;
    }

    /**
     * @throws TenantNotFound
     */
    private function tenant(): Tenant
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            throw new TenantNotFound("Tenant {$this->tenantId} tidak ditemukan.");
        }

        return $tenant;
    }
}
