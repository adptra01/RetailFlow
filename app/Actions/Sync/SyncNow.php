<?php

namespace App\Actions\Sync;

use App\Models\Device;
use App\Support\Sync\Outbox;
use App\Support\Sync\SyncPushSummary;
use App\Support\Sync\SyncTransport;
use RuntimeException;

/**
 * Satu putaran sinkronisasi manual dari UI (Sprint 4 item 8 — "Sinkronkan
 * sekarang"; §11.3).
 *
 * Perangkat mengirim antrean outbox-nya ke server lalu mengembalikan
 * ringkasan hasil. Token perangkat diambil dari parameter eksplisit, atau
 * dari `config('pos.sync.device_token')` (instalasi/on-prem, lihat
 * `config/pos.php`). Bila token tidak tersedia, aksi melempar
 * `RuntimeException` agar UI dapat memberi tahu pengguna alih-alih diam.
 *
 * Idempotensi & crash-safety tetap dijaga `PushOutbox` (§11.3): hasil tak
 * diketahui → operasi kembali ke `pending` dan diulang.
 */
final class SyncNow
{
    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?string $deviceId = null,
        private readonly ?SyncTransport $transport = null,
        private readonly ?Outbox $outbox = null,
    ) {}

    /**
     * @param  string|null  $token  token perangkat; null = pakai konfigurasi
     */
    public function handle(?string $token = null, ?int $limit = null): SyncPushSummary
    {
        $token ??= (string) config('pos.sync.device_token');

        if ($token === '') {
            throw new RuntimeException(
                'Token sinkronisasi perangkat belum dikonfigurasi (POS_SYNC_DEVICE_TOKEN).'
            );
        }

        if ($this->deviceId === null) {
            throw new RuntimeException('Perangkat tidak diketahui untuk sinkronisasi.');
        }

        $device = Device::query()->find($this->deviceId);

        if ($device === null) {
            throw new RuntimeException('Perangkat tidak ditemukan.');
        }

        if ($device->isRevoked()) {
            throw new RuntimeException('Perangkat telah dicabut — sinkronisasi ditolak (§15).');
        }

        $transport = $this->transport ?? app(SyncTransport::class);

        $summary = (new PushOutbox(
            $this->tenantId ?? (string) $device->tenant_id,
            $this->deviceId,
            $transport,
            $this->outbox,
        ))->handle($token, $limit);

        $device->markSeen();

        return $summary;
    }
}
