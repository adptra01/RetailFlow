<?php

namespace App\Support\Sync;

use App\Enums\ServerChangeOutcome;
use App\Enums\SyncChangeOperation;
use App\Enums\SyncConflictStatus;
use App\Enums\SyncConflictType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Supplier;
use App\Models\SyncConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Menerapkan perubahan server pada basis data lokal perangkat (§11.3
 * langkah 10).
 *
 * Aturan yang ditegakkan:
 *
 *  - **tidak memantulkan perubahan sendiri** (§11.1): perubahan yang
 *    `device_id`-nya perangkat ini dilewati, karena datanya sudah ada lokal;
 *  - **hanya data acuan yang dicerminkan**: katalog (produk, kategori, satuan
 *    harga/promo, menu, bahan), pelanggan, dan pemasok. Dokumen transaksi
 *    (penjualan, pesanan, pembelian, mutasi stok) adalah catatan yang
 *    dibuat perangkat masing-masing; menimpanya dari server akan menghapus
 *    pekerjaan lokal (§11.4 "transaksi perangkat tidak dihapus diam-diam");
 *  - **idempoten** (§11.1): data lokal yang sudah sama dilewati, dan
 *    `updated_at` lokal disetel ke waktu perubahan server sehingga penerapan
 *    ulang tidak dianggap suntingan lokal yang lebih baru;
 *  - **tidak menimpa suntingan lokal yang lebih baru** (§11.3 langkah 11):
 *    bila baris lokal diubah setelah perubahan server itu dibuat, perubahan
 *    server TIDAK diterapkan dan sebuah kasus konflik `data` dicatat untuk
 *    diselesaikan pengguna berwenang;
 *  - **tanpa penjurnalan ulang**: penerapan dibungkus
 *    `SyncChangeJournal::withoutRecording()` supaya perubahan yang ditarik
 *    tidak dicatat kembali sebagai perubahan baru dan tidak memantul ke
 *    perangkat lain tanpa henti.
 */
final class DatabaseServerChangeApplier
{
    /**
     * Jenis entitas yang dicerminkan perangkat, dipetakan ke modelnya.
     * Kunci mengikuti `SyncChangeJournal::entityTypeFor()` (snake case nama
     * kelas).
     *
     * @var array<string, class-string<Model>>
     */
    private const MIRRORED = [
        'product' => Product::class,
        'category' => Category::class,
        'customer' => Customer::class,
        'supplier' => Supplier::class,
        'promotion' => Promotion::class,
        'menu' => Menu::class,
        'ingredient' => Ingredient::class,
    ];

    /**
     * @param  array<string, mixed>  $change
     */
    public function apply(array $change, string $tenantId, ?string $ownDeviceId = null): ServerChangeOutcome
    {
        $entityType = (string) ($change['entity_type'] ?? '');
        $entityId = (string) ($change['entity_id'] ?? '');
        $operation = (string) ($change['operation'] ?? '');
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        // §11.1: perubahan milik perangkat ini sendiri tidak diterapkan ulang.
        if ($ownDeviceId !== null && ($change['device_id'] ?? null) === $ownDeviceId) {
            return ServerChangeOutcome::Skipped;
        }

        // §11.5: jenis entitas yang tidak dicerminkan perangkat dilewati tanpa
        // menghapus data lokal apa pun.
        $modelClass = self::MIRRORED[$entityType] ?? null;

        if ($modelClass === null || ! Str::isUuid($entityId)) {
            return ServerChangeOutcome::Skipped;
        }

        $changedAt = $this->changedAt($change);

        return SyncChangeJournal::withoutRecording(function () use (
            $modelClass,
            $entityId,
            $operation,
            $payload,
            $changedAt,
            $tenantId,
            $ownDeviceId,
            $change,
        ): ServerChangeOutcome {
            /** @var Model|null $local */
            $local = $modelClass::query()->where('uuid', $entityId)->first();

            if ($operation === SyncChangeOperation::Deleted->value) {
                return $this->applyDeletion($local, $changedAt) ?? $this->conflict($change, $local, $tenantId, $ownDeviceId, $changedAt);
            }

            if ($local === null) {
                return $this->create($modelClass, $entityId, $payload, $tenantId, $changedAt);
            }

            // §11.3 langkah 11: suntingan lokal yang lebih baru menang sampai
            // pengguna berwenang menyelesaikan konfliknya.
            if ($this->isLocallyNewer($local, $changedAt)) {
                return $this->conflict($change, $local, $tenantId, $ownDeviceId, $changedAt);
            }

            return $this->update($local, $payload, $changedAt);
        });
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $payload
     */
    private function create(string $modelClass, string $entityId, array $payload, string $tenantId, ?CarbonImmutable $changedAt): ServerChangeOutcome
    {
        $attributes = $this->attributesFrom($payload);

        if ($attributes === []) {
            return ServerChangeOutcome::Skipped;
        }

        /** @var Model $model */
        $model = new $modelClass;

        $model->fill($attributes);

        // `uuid` dan `tenant_id` di luar jaminan `$fillable`: identitas
        // sinkronisasi (§17.1) dan tenant yang menerima perubahan (§12).
        $model->setAttribute('uuid', $entityId);

        if ($model->getAttribute('tenant_id') === null) {
            $model->setAttribute('tenant_id', $tenantId);
        }

        if ($changedAt !== null && $model->getAttribute('created_at') === null) {
            $model->setAttribute('created_at', $changedAt);
        }

        if ($changedAt !== null) {
            $model->setAttribute('updated_at', $changedAt);
        }

        $model->save();

        return ServerChangeOutcome::Applied;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function update(Model $local, array $payload, ?CarbonImmutable $changedAt): ServerChangeOutcome
    {
        $attributes = $this->attributesFrom($payload);

        if ($attributes !== []) {
            $local->fill($attributes);
        }

        if (! $local->isDirty()) {
            // §11.1: penerapan ulang perubahan yang sama tidak berefek.
            return ServerChangeOutcome::Skipped;
        }

        if ($changedAt !== null) {
            $local->setAttribute('updated_at', $changedAt);
        }

        $local->save();

        return ServerChangeOutcome::Applied;
    }

    /**
     * @return ServerChangeOutcome|null null bila baris lokal tidak ada
     */
    private function applyDeletion(?Model $local, ?CarbonImmutable $changedAt): ?ServerChangeOutcome
    {
        if ($local === null) {
            return ServerChangeOutcome::Skipped;
        }

        if ($this->isLocallyNewer($local, $changedAt)) {
            return null;
        }

        $local->delete();

        return ServerChangeOutcome::Applied;
    }

    /**
     * Catat konflik `data` agar tidak ada perubahan yang hilang diam-diam
     * (§11.3 langkah 11). Idempoten per `(operation_id, type)`.
     *
     * @param  array<string, mixed>  $change
     */
    private function conflict(
        array $change,
        ?Model $local,
        string $tenantId,
        ?string $ownDeviceId,
        ?CarbonImmutable $changedAt,
    ): ServerChangeOutcome {
        $sequence = $change['sequence'] ?? null;

        SyncConflict::query()->updateOrCreate(
            [
                'operation_id' => 'pull:'.($sequence ?? Str::uuid()->toString()),
                'type' => SyncConflictType::Data->value,
            ],
            [
                'tenant_id' => $tenantId,
                'device_id' => $ownDeviceId,
                'status' => SyncConflictStatus::Open->value,
                'entity_type' => $change['entity_type'] ?? null,
                'entity_id' => $change['entity_id'] ?? null,
                'details' => [
                    'reason' => 'local_change_is_newer',
                    'server_sequence' => $sequence,
                    'server_changed_at' => $changedAt?->toIso8601String(),
                    'local_updated_at' => $local?->getAttribute('updated_at')?->toIso8601String(),
                ],
            ],
        );

        return ServerChangeOutcome::Conflicted;
    }

    private function isLocallyNewer(Model $local, ?CarbonImmutable $changedAt): bool
    {
        if ($changedAt === null) {
            return false;
        }

        $localUpdatedAt = $local->getAttribute('updated_at');

        return $localUpdatedAt !== null
            && CarbonImmutable::parse($localUpdatedAt)->greaterThan($changedAt);
    }

    /**
     * Atribut baru dari payload jurnal: `id` lokal, identitas, dan jejak waktu
     * tidak diambil dari server (identitas dari `entity_id`, waktu dari
     * perubahan jurnal).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attributesFrom(array $payload): array
    {
        unset(
            $payload['id'],
            $payload['uuid'],
            $payload['tenant_id'],
            $payload['created_at'],
            $payload['updated_at'],
            $payload['deleted_at'],
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function changedAt(array $change): ?CarbonImmutable
    {
        $createdAt = $change['created_at'] ?? null;

        if (! is_string($createdAt) || $createdAt === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($createdAt);
        } catch (\Throwable) {
            return null;
        }
    }
}
