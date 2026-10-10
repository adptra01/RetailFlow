<?php

namespace App\Actions\Sync;

use App\Actions\Sales\CreateSale;
use App\Enums\Permission;
use App\Enums\SyncConflictStatus;
use App\Enums\SyncConflictType;
use App\Enums\SyncInboxStatus;
use App\Models\SyncConflict;
use App\Models\SyncInbox;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyelesaikan kasus konflik sinkronisasi (§11.4, §11.5).
 *
 * Dua jalur, keduanya hanya untuk pengguna berwenang (izin `stock.adjust`):
 *
 * - `allow()`  — owner mengaktifkan/menyetujui kebijakan stok negatif untuk
 *   kasus ini: operasi penjualan yang tertahan diterapkan ulang dengan stok
 *   negatif diizinkan, lalu kasus ditandai selesai dan baris inbox menjadi
 *   `applied`.
 * - `dismiss()` — kasus ditutup tanpa penerapan (mis. transaksi memang batal
 *   di perangkat). Baris inbox tetap `conflict` agar tidak hilang (§11.5
 *   "data lokal tidak boleh dihapus").
 *
 * Operasi pengguna dicatat di `resolution` untuk jejak audit.
 */
final class ResolveSyncConflict
{
    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?User $actor = null,
    ) {}

    /**
     * Terapkan ulang penjualan tertahan dengan stok negatif diizinkan.
     */
    public function allow(SyncConflict $conflict, ?string $note = null): SyncConflict
    {
        ActionAuthorizer::assertIf($this->actor, Permission::AdjustStock);

        if ($conflict->type !== SyncConflictType::Stock) {
            throw new RuntimeException('Hanya konflik stok yang dapat diselesaikan dengan jalur ini.');
        }

        return DB::transaction(function () use ($conflict, $note): SyncConflict {
            $conflict = SyncConflict::query()->lockForUpdate()->findOrFail($conflict->getKey());
            $operation = $this->operationFor($conflict);

            $transaction = (new CreateSale)->handle([
                ...($operation->payload ?? []),
                'tenant_id' => $conflict->tenant_id,
                'device_id' => $conflict->device_id,
                'client_request_id' => $operation->operation_id,
                'allow_negative_stock' => true,
            ]);

            $operation->markApplied($transaction->uuid);

            return $this->close($conflict, SyncConflictStatus::Resolved, [
                'action' => 'allow_negative_stock',
                'note' => $note,
                'transaction_uuid' => $transaction->uuid,
            ]);
        });
    }

    /**
     * Tutup kasus tanpa penerapan ulang.
     */
    public function dismiss(SyncConflict $conflict, ?string $note = null): SyncConflict
    {
        ActionAuthorizer::assertIf($this->actor, Permission::AdjustStock);

        return DB::transaction(function () use ($conflict, $note): SyncConflict {
            $conflict = SyncConflict::query()->lockForUpdate()->findOrFail($conflict->getKey());

            return $this->close($conflict, SyncConflictStatus::Dismissed, [
                'action' => 'dismiss',
                'note' => $note,
            ]);
        });
    }

    private function close(SyncConflict $conflict, SyncConflictStatus $status, array $resolution): SyncConflict
    {
        $conflict->status = $status;
        $conflict->resolution = $resolution;
        $conflict->resolved_by = $this->actor?->id;
        $conflict->resolved_at = now();
        $conflict->save();

        return $conflict;
    }

    private function operationFor(SyncConflict $conflict): SyncInbox
    {
        $operation = SyncInbox::query()
            ->where('operation_id', $conflict->operation_id)
            ->first();

        if ($operation === null) {
            throw new RuntimeException('Operasi sumber konflik tidak ditemukan.');
        }

        if ($operation->status === SyncInboxStatus::Applied) {
            throw new RuntimeException('Operasi konflik sudah diterapkan sebelumnya.');
        }

        return $operation;
    }
}
