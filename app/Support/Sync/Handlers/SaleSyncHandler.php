<?php

namespace App\Support\Sync\Handlers;

use App\Actions\Sales\CreateSale;
use App\Enums\SyncOperationType;
use App\Exceptions\Sales\InsufficientStock;
use App\Models\SyncInbox;
use App\Support\Sync\SyncApplicationResult;
use App\Support\Sync\SyncOperationHandler;
use Throwable;

/**
 * Menerapkan operasi penjualan dari perangkat ke domain (§11.3 langkah 6).
 *
 * Idempotensi: `operation_id` dipakai sebagai `client_request_id` pada
 * `CreateSale`, sehingga pengiriman ulang / penerapan ulang mengembalikan
 * penjualan yang sama alih-alih menggandakannya (§11.1).
 *
 * Klasifikasi kegagalan:
 * - stok kurang → `conflict` (§11.4: jangan hapus diam-diam, buat kasus);
 * - pelanggaran aturan lain → `rejected` (§11.5).
 */
final class SaleSyncHandler implements SyncOperationHandler
{
    public function supports(SyncInbox $operation): bool
    {
        return $operation->entity_type === 'sale'
            && $operation->operation_type === SyncOperationType::Create->value;
    }

    public function apply(SyncInbox $operation): SyncApplicationResult
    {
        $payload = $operation->payload ?? [];

        if (! isset($payload['user_id'])) {
            return SyncApplicationResult::rejected('Operasi penjualan tanpa user_id.');
        }

        // Identitas tenant/perangkat selalu dari server, bukan payload (§12).
        $data = $payload;
        $data['tenant_id'] = $operation->tenant_id;
        $data['device_id'] = $operation->device_id;
        $data['client_request_id'] = $operation->operation_id;

        try {
            $transaction = (new CreateSale)->handle($data);
        } catch (InsufficientStock $e) {
            return SyncApplicationResult::conflict($e->getMessage());
        } catch (Throwable $e) {
            return SyncApplicationResult::rejected($e->getMessage());
        }

        return SyncApplicationResult::applied($transaction->uuid);
    }
}
