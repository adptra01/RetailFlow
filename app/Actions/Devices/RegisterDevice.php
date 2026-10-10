<?php

namespace App\Actions\Devices;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Device;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Devices\DeviceRegistration;
use App\Support\Devices\DeviceToken;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Use case pendaftaran perangkat (Sprint 4 item 1 — Identitas perangkat;
 * NFR-03, §15: "Perangkat memiliki identitas dan kredensial sendiri").
 *
 * Menghasilkan token unik per perangkat; hanya hash yang disimpan, plaintext
 * dikembalikan sekali. Perangkat yang dicabut langsung ditolak saat
 * sinkronisasi berikutnya (§15).
 *
 * Otorisasi: izin administratif FR-12 `user.manage` bila aktor diberikan
 * (pendaftaran bisa juga dijalankan saat onboarding/CLI tanpa aktor).
 */
class RegisterDevice
{
    private readonly AuditRecorder $audit;

    public function __construct(
        private readonly string $tenantId,
        ?AuditRecorder $audit = null,
    ) {
        $this->audit = $audit ?? new AuditRecorder($this->tenantId);
    }

    /**
     * @throws ActionForbidden|ValidationException
     */
    public function handle(
        ?User $actor,
        string $name,
        string $platform,
        ?string $locationId = null,
        ?string $appVersion = null,
        ?string $reason = null,
    ): DeviceRegistration {
        ActionAuthorizer::assertIf($actor, Permission::ManageUsers);

        Validator::make([
            'device_name' => $name,
            'platform' => $platform,
            'location_id' => $locationId,
            'app_version' => $appVersion,
        ], [
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['required', 'string', 'max:30'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ])->validate();

        $token = DeviceToken::generate();

        $device = Device::create([
            'tenant_id' => $this->tenantId,
            'location_id' => $locationId,
            'device_name' => $name,
            'platform' => $platform,
            'app_version' => $appVersion,
            'token_hash' => $token->hash,
            'token_prefix' => $token->prefix,
            'last_seen_at' => now(),
        ]);

        $this->audit->record(
            AuditAction::DeviceRegistered,
            $actor,
            entityType: 'device',
            entityId: $device->id,
            reason: $reason,
            metadata: [
                'device_name' => $name,
                'platform' => $platform,
                'token_prefix' => $token->prefix,
            ],
        );

        return new DeviceRegistration($device, $token->plaintext);
    }
}
