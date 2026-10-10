<?php

namespace App\Actions\Devices;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\Devices\DeviceNotFound;
use App\Models\Device;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;

/**
 * Use case pencabutan akses perangkat (Sprint 4 item 1; §15: "Perangkat yang
 * dicabut harus ditolak pada sinkronisasi berikutnya").
 *
 * Idempoten: perangkat yang sudah dicabut tidak menghasilkan jejak audit baru.
 * Pencabutan bersifat online — perangkat yang lama offline baru tertolak saat
 * sinkronisasi berikutnya (batasan §15, tidak dijanjikan instan).
 */
class RevokeDevice
{
    private readonly AuditRecorder $audit;

    public function __construct(
        private readonly string $tenantId,
        ?AuditRecorder $audit = null,
    ) {
        $this->audit = $audit ?? new AuditRecorder($this->tenantId);
    }

    /**
     * @throws ActionForbidden|DeviceNotFound
     */
    public function handle(User $actor, string $deviceId, ?string $reason = null): Device
    {
        ActionAuthorizer::assert($actor, Permission::ManageUsers);

        $device = Device::query()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($deviceId)
            ->first();

        if ($device === null) {
            throw new DeviceNotFound("Perangkat {$deviceId} tidak ditemukan.");
        }

        if ($device->isRevoked()) {
            return $device;
        }

        $device->update(['revoked_at' => now()]);

        $this->audit->record(
            AuditAction::DeviceRevoked,
            $actor,
            entityType: 'device',
            entityId: $device->id,
            reason: $reason,
            metadata: ['device_name' => $device->device_name],
        );

        return $device;
    }
}
