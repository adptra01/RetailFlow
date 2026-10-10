<?php

namespace App\Support\Devices;

use App\Models\Device;

/**
 * Hasil pendaftaran perangkat: perangkat tersimpan + token plaintext yang
 * hanya dikembalikan SEKALI (NFR-03, §15).
 */
final readonly class DeviceRegistration
{
    public function __construct(
        public Device $device,
        public string $token,
    ) {}
}
