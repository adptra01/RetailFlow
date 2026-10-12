<?php

namespace App\Support\Sync;

use App\Enums\SyncChangeOperation;
use App\Models\SyncChange;
use App\Support\Devices\DeviceContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jurnal perubahan sisi server (item Sprint 4.4 — Cursor sinkronisasi).
 *
 * Mencatat create/update/delete record bisnis ke `sync_changes` dengan
 * `sequence` monoton sebagai kursor. Dipakai oleh trait `JournalSyncChanges`.
 *
 * Tenant diambil dari record, lalu `TenantContext`. Perubahan tanpa tenant
 * (seeder/CLI tanpa konteks) dilewati: tidak ada tenant tujuan untuk
 * menyinkronkannya, dan `sync_changes.tenant_id` wajib diisi.
 */
final class SyncChangeJournal
{
    /**
     * Saat true, `record()` tidak menulis apa pun. Dipakai ketika perangkat
     * MENERAPKAN perubahan yang ditarik dari server (§11.3 langkah 10):
     * perubahan itu sudah ada di jurnal server, dan mencatatnya lagi akan
     * memantulkannya ke perangkat lain tanpa henti (§11.1).
     */
    private static bool $suppressed = false;

    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?string $deviceId = null,
    ) {}

    /**
     * Jalankan `$callback` dengan penjurnalan dimatikan.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    public function record(Model $model, SyncChangeOperation $operation): ?SyncChange
    {
        if (self::$suppressed) {
            return null;
        }

        $tenantId = $this->tenantId ?? $this->tenantIdFor($model);

        if ($tenantId === null) {
            return null;
        }

        return SyncChange::create([
            'tenant_id' => $tenantId,
            'entity_type' => $this->entityTypeFor($model),
            'entity_id' => $this->entityIdFor($model),
            'operation' => $operation->value,
            'payload' => $this->payloadFor($model),
            'device_id' => $this->deviceId ?? app(DeviceContext::class)->id(),
        ]);
    }

    private function tenantIdFor(Model $model): ?string
    {
        $tenantId = $model->getAttribute('tenant_id');

        return is_string($tenantId) && $tenantId !== ''
            ? $tenantId
            : app(TenantContext::class)->id();
    }

    private function entityTypeFor(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    private function entityIdFor(Model $model): string
    {
        $uuid = $model->getAttribute('uuid');

        return is_string($uuid) && $uuid !== ''
            ? $uuid
            : (string) $model->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Model $model): array
    {
        // attributesToArray() menerapkan cast dan mengecualikan $hidden
        // (mis. password pada User) tanpa memuat accessor/relasi.
        return $model->attributesToArray();
    }
}
