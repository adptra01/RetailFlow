<?php

namespace App\Support\Devices;

use App\Models\Device;

/**
 * Request-scoped holder perangkat yang sedang melayani request (handshake
 * sinkronisasi). Seperti `TenantContext`, sengaja tidak dipersistensi agar
 * worker/tests tetap tanpa perangkat sampai di-bind (NFR-03, §15).
 */
class DeviceContext
{
    private ?Device $device = null;

    public function set(?Device $device): void
    {
        $this->device = $device;
    }

    public function get(): ?Device
    {
        return $this->device;
    }

    public function id(): ?string
    {
        return $this->device?->getKey();
    }

    public function has(): bool
    {
        return $this->device !== null;
    }
}
