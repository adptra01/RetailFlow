<?php

namespace App\Actions\Sync;

use App\Enums\ServerChangeOutcome;
use App\Models\Device;
use App\Support\Sync\DatabaseServerChangeApplier;
use App\Support\Sync\SyncPullSummary;
use App\Support\Sync\SyncTransport;
use App\Support\Tenancy\TenantContext;
use RuntimeException;

/**
 * Satu putaran penarikan perubahan server ke perangkat (§11.3 langkah 9–10).
 *
 * Urutan satu putaran penuh: perangkat mengirim antrean outbox-nya
 * (`SyncNow`, langkah 3–8) lalu menarik perubahan server sejak kursor
 * terakhir (langkah 9) dan menerapkannya pada basis data lokal (langkah 10).
 *
 * Kursor disimpan pada baris `devices.last_sync_cursor` sehingga putaran
 * berikutnya melanjutkan dari posisi terakhir. Kursor HANYA bergerak setelah
 * halaman yang bersangkutan benar-benar diterapkan; kegagalan transport
 * melempar `SyncTransportException` dan putaran diulang dari kursor lama
 * (§11.3 "Pengiriman ulang setelah timeout harus aman") — tidak ada
 * perubahan yang terlewat karena kursor tidak pernah melompati data.
 *
 * Halaman diambil berurutan sampai `has_more` false atau batas halaman
 * (`pos.sync.max_pull_pages`) tercapai, agar perangkat yang lama offline
 * tetap dapat menyusul tanpa satu request raksasa (§11.5).
 */
final class PullServerChanges
{
    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?string $deviceId = null,
        private readonly ?SyncTransport $transport = null,
        private readonly ?DatabaseServerChangeApplier $applier = null,
    ) {}

    /**
     * @param  string|null  $token  token perangkat; null = pakai konfigurasi
     */
    public function handle(?string $token = null, ?int $limit = null, ?int $maxPages = null): SyncPullSummary
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

        $tenantId = $this->tenantId ?? (string) ($device->tenant_id ?? app(TenantContext::class)->id());

        if ($tenantId === '') {
            throw new RuntimeException('Tenant tidak diketahui saat menarik perubahan.');
        }

        $transport = $this->transport ?? app(SyncTransport::class);
        $applier = $this->applier ?? new DatabaseServerChangeApplier;

        $limit = $limit ?? (int) config('pos.offline.batch_size', 100);
        $maxPages = $maxPages ?? (int) config('pos.sync.max_pull_pages', 20);

        $cursor = $device->last_sync_cursor;
        $applied = $skipped = $conflicted = $pages = 0;
        $hasMore = false;

        while ($pages < $maxPages) {
            // Melempar SyncTransportException bila hasil tidak diketahui:
            // kursor tidak digeser sehingga putaran berikutnya mengulang (§11.3).
            $batch = $transport->pull($token, $cursor, $limit);

            $pages++;

            foreach ($batch->changes as $change) {
                if (! is_array($change)) {
                    continue;
                }

                match ($applier->apply($change, $tenantId, $this->deviceId)) {
                    ServerChangeOutcome::Applied => $applied++,
                    ServerChangeOutcome::Skipped => $skipped++,
                    ServerChangeOutcome::Conflicted => $conflicted++,
                };
            }

            if ($batch->cursor !== null) {
                $cursor = $batch->cursor;
                $this->persistCursor($cursor);
            }

            $hasMore = $batch->hasMore;

            if (! $hasMore) {
                break;
            }
        }

        $device->markSeen();

        return new SyncPullSummary(
            applied: $applied,
            skipped: $skipped,
            conflicted: $conflicted,
            pages: $pages,
            cursor: $cursor,
            hasMore: $hasMore,
        );
    }

    private function persistCursor(string $cursor): void
    {
        Device::query()
            ->whereKey($this->deviceId)
            ->update(['last_sync_cursor' => $cursor]);
    }
}
