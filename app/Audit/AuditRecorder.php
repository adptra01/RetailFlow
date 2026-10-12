<?php

namespace App\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Devices\DeviceContext;
use App\Support\Tenancy\TenantContext;

/**
 * Use case infra §10.4 ("Logging dan audit") — perekam jejak audit FR-12.
 *
 * Menulis baris append-only `audit_logs` secara terpusat sehingga semua
 * panggilan memakai resolusi tenant/aktor/perangkat yang sama:
 *   1. tenant eksplisit dari konstruktor (CLI/test, deterministik);
 *   2. fallback tenant aktor;
 *   3. fallback konteks request (`TenantContext`).
 *
 * `deviceId` eksplisit memungkinkan pencatatan perangkat; bila kosong,
 * fallback ke `DeviceContext` (handshake sinkronisasi Sprint 4), dan baru
 * dibiarkan null bila tidak ada perangkat ter-bind.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?string $deviceId = null,
    ) {}

    /**
     * Salinan perekam untuk tenant tertentu.
     *
     * Dipakai use case yang tenant-nya baru diketahui setelah memuat model
     * (mis. webhook gateway, yang datang tanpa konteks tenant — §12). Bila
     * perekam ini sudah diikat ke tenant secara eksplisit (konstruktor/CLI/
     * pengujian), tenant itu yang menang; bila belum (mis. di-resolve container
     * tanpa konteks), tenant model yang dipakai.
     */
    public function withTenant(?string $tenantId): self
    {
        if ($this->tenantId !== null || $tenantId === null) {
            return $this;
        }

        return new self($tenantId, $this->deviceId);
    }

    /**
     * Catat satu tindakan sensitif (append-only; tidak pernah diupdate).
     *
     * @param  array<string, mixed>  $metadata  data konteks tambahan (JSON)
     */
    public function record(
        AuditAction|string $action,
        ?User $actor = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $reason = null,
        array $metadata = [],
    ): AuditLog {
        $action = $action instanceof AuditAction ? $action->value : $action;

        return AuditLog::create([
            'tenant_id' => $this->resolveTenantId($actor),
            'actor_id' => $actor?->id,
            'device_id' => $this->deviceId ?? app(DeviceContext::class)->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    private function resolveTenantId(?User $actor): ?string
    {
        if ($this->tenantId !== null) {
            return $this->tenantId;
        }

        return $actor?->tenant_id ?? app(TenantContext::class)->id();
    }
}
