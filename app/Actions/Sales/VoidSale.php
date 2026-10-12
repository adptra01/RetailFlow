<?php

namespace App\Actions\Sales;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SyncOperationType;
use App\Exceptions\Sales\VoidNotAllowed;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Pembatalan transaksi terkontrol — void (§17.4 "Retur dan void", FR-04/FR-12).
 *
 * Menggantikan penghapusan permanen yang lama. Menegakkan, di satu tempat:
 *  - **tidak menghapus**: transaksi ditandai `payment_status = 'void'` dan
 *    tetap tersimpan beserta itemnya (§17.4 "Transaksi selesai tidak dihapus
 *    permanen");
 *  - **alasan wajib**: pembatalan tanpa alasan ditolak (FR-12 "alasan jika
 *    diperlukan" — di sini selalu diperlukan);
 *  - **izin wajib**: bila aktor diberikan, izin `transaction.void` diperiksa
 *    lewat `ActionAuthorizer` (FR-12);
 *  - **idempoten**: pemanggilan ulang atas transaksi yang sudah batal tidak
 *    mengembalikan stok lagi dan tidak menulis mutasi/audit tambahan;
 *  - **mutasi balik**: stok dikembalikan dengan membalik mutasi penjualan yang
 *    tercatat (`reference_type = 'sale'`), bukan menebak dari item — sehingga
 *    jumlah yang dikembalikan selalu sama dengan yang pernah dikurangi (FR-08),
 *    dan dapat dimatikan lewat kebijakan `pos.sales.restore_stock_on_void`;
 *  - **piutang dijaga (FR-07)**: piutang penjualan yang belum dibayar dibatalkan
 *    dan saldo pelanggan dikoreksi. Bila sudah ada pembayaran piutang, void
 *    ditolak sampai koreksinya diselesaikan — bukan menghapus jejak pembayaran;
 *  - **jejak audit**: satu baris `sale.voided` dengan aktor, perangkat, alasan,
 *    dan ringkasan apa yang berubah (FR-12);
 *  - **siap offline (§11.1/§11.3)**: bila pembatalan berasal dari perangkat
 *    (`device_id` ada), satu operasi `sale`/`update` dimasukkan ke outbox
 *    perangkat di dalam transaksi yang sama, sehingga pembatalan yang
 *    dilakukan tanpa jaringan ikut terkirim ke server.
 */
class VoidSale
{
    /**
     * @param  array{
     *     transaction_id?: int,
     *     uuid?: string|null,
     *     user_id: int,
     *     reason: string,
     *     tenant_id?: string|null,
     *     device_id?: string|null,
     *     restore_stock?: bool|null,
     *     client_request_id?: string|null,
     *     enqueue_for_sync?: bool,
     * }  $data
     * @return array{
     *     transaction: Transaction,
     *     already_void: bool,
     *     stock_restored: bool,
     *     restored_items: int,
     *     receivable_voided: bool,
     * }
     */
    public function handle(array $data, ?User $actor = null): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw VoidNotAllowed::reasonRequired();
        }

        ActionAuthorizer::assertIf($actor, Permission::VoidTransaction);

        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        return DB::transaction(function () use ($data, $reason, $tenantId): array {
            $transaction = $this->lockTransaction($data);
            $recordTenantId = $transaction->tenant_id ?? $tenantId;

            // Idempoten (§17.4): pengulangan tidak mengubah apa pun lagi.
            if ($transaction->isVoided()) {
                return [
                    'transaction' => $transaction,
                    'already_void' => true,
                    'stock_restored' => (bool) $transaction->stock_restored,
                    'restored_items' => 0,
                    'receivable_voided' => false,
                ];
            }

            $restoreStock = (bool) ($data['restore_stock']
                ?? config('pos.sales.restore_stock_on_void', true));

            $previousStatus = $transaction->payment_status;

            $restoredItems = $restoreStock
                ? $this->restoreStock($transaction, (int) $data['user_id'], $recordTenantId, $reason)
                : 0;

            $receivableVoided = $this->voidReceivable($transaction);

            $transaction->forceFill([
                'payment_status' => Transaction::PAYMENT_STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $data['user_id'],
                'void_reason' => $reason,
                'stock_restored' => $restoreStock,
            ])->save();

            AuditLog::create([
                'tenant_id' => $recordTenantId,
                'actor_id' => $data['user_id'],
                'device_id' => $data['device_id'] ?? null,
                'action' => AuditAction::SaleVoided->value,
                'entity_type' => 'sale',
                'entity_id' => $transaction->uuid,
                'metadata' => [
                    'invoice_number' => $transaction->invoice_number,
                    'reason' => $reason,
                    'previous_payment_status' => $previousStatus,
                    'stock_restored' => $restoreStock,
                    'restored_items' => $restoredItems,
                    'receivable_voided' => $receivableVoided,
                    // Idempotensi perangkat (§11.1): id operasi yang memicu
                    // pembatalan ini, agar terkirim ulang tidak berlipat.
                    'client_request_id' => $data['client_request_id'] ?? null,
                ],
            ]);

            $this->enqueueForSync($transaction, $data, $recordTenantId, $restoreStock);

            return [
                'transaction' => $transaction,
                'already_void' => false,
                'stock_restored' => $restoreStock,
                'restored_items' => $restoredItems,
                'receivable_voided' => $receivableVoided,
            ];
        });
    }

    /**
     * §11.1/§11.3: pembatalan dari perangkat ikut masuk outbox perangkat agar
     * terkirim ke server bila perangkat kembali online. Dipanggil DI DALAM
     * transaksi domain sehingga entri outbox hilang bila void dibatalkan.
     *
     * @param  array<string, mixed>  $data
     */
    private function enqueueForSync(Transaction $transaction, array $data, ?string $tenantId, bool $restoreStock): void
    {
        $deviceId = $data['device_id'] ?? null;

        if ($deviceId === null || $tenantId === null) {
            return;
        }

        if (($data['enqueue_for_sync'] ?? true) === false) {
            return;
        }

        (new Outbox($tenantId))->enqueue(
            (string) $deviceId,
            'sale',
            $transaction->uuid,
            SyncOperationType::Update,
            [
                'void' => true,
                'user_id' => $data['user_id'],
                'reason' => $data['reason'] ?? null,
                'restore_stock' => $restoreStock,
            ],
            $data['client_request_id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lockTransaction(array $data): Transaction
    {
        $query = Transaction::query()->lockForUpdate();

        if (! empty($data['transaction_id'])) {
            $query->whereKey($data['transaction_id']);
        } elseif (! empty($data['uuid'])) {
            $query->where('uuid', $data['uuid']);
        } else {
            throw VoidNotAllowed::notFound();
        }

        return $query->first() ?? throw VoidNotAllowed::notFound();
    }

    /**
     * Mutasi balik: kembalikan tepat sebesar yang pernah dikurangi. Sumber
     * kebenaran adalah ledger penjualan yang tercatat, bukan perhitungan ulang
     * dari item (faktor konversi satuan bisa berubah setelah penjualan).
     */
    private function restoreStock(Transaction $transaction, int $userId, ?string $tenantId, string $reason): int
    {
        $movements = StockMovement::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $transaction->id)
            ->lockForUpdate()
            ->get();

        $restored = 0;

        foreach ($movements as $movement) {
            $quantity = abs((int) $movement->quantity_change);

            if ($quantity === 0) {
                continue;
            }

            $product = Product::query()->lockForUpdate()->find($movement->product_id);

            if (! $product) {
                continue;
            }

            $before = (int) $product->stock;
            $product->stock = $before + $quantity;
            // saveQuietly: ledger di bawah bersifat otoritatif (pola CreateSale).
            $product->saveQuietly();

            StockMovement::create([
                'product_id' => $product->id,
                'quantity_change' => $quantity,
                'before_stock' => $before,
                'after_stock' => (int) $product->stock,
                'reference_type' => 'sale_void',
                'reference_id' => $transaction->id,
                'user_id' => $userId,
                'notes' => 'Pembatalan '.$transaction->invoice_number.': '.$reason,
                'tenant_id' => $tenantId,
            ]);

            $restored++;
        }

        return $restored;
    }

    private function voidReceivable(Transaction $transaction): bool
    {
        $receivable = Receivable::query()
            ->where('transaction_id', $transaction->id)
            ->lockForUpdate()
            ->first();

        if ($receivable === null || $receivable->status === 'void') {
            return false;
        }

        // FR-07: pembayaran piutang bersifat append-only. Void tidak boleh
        // menyembunyikannya — koreksi piutang harus diselesaikan lebih dahulu.
        if ($receivable->payments()->exists()) {
            throw VoidNotAllowed::receivableHasPayments($transaction->invoice_number);
        }

        $customer = $receivable->customer_id !== null
            ? Customer::query()->lockForUpdate()->find($receivable->customer_id)
            : null;

        if ($customer !== null) {
            // Transitional mirror: saldo pelanggan adalah cache yang hanya
            // diubah aksi domain; koreksi kebalikan dari saat penjualan dibuat.
            $customer->decrement('balance', (new Money((int) $receivable->original_amount_minor))->major());
        }

        $receivable->forceFill([
            'status' => 'void',
            'remaining_minor' => 0,
        ])->save();

        return true;
    }
}
