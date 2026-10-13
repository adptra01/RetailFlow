<?php

namespace App\Support\Alerts;

use App\Backup\BackupManager;
use App\Enums\PaymentVerification;
use App\Enums\SyncStatus;
use App\Models\Alert;
use App\Models\Device;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\StockOpname;
use App\Models\SyncOutbox;
use App\Models\Transaction;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menghasilkan alert lokal (FR-10) dari kondisi yang bisa dilihat data lokal:
 *  1. stok di bawah ambang minimum (stock.low);
 *  2. produk tanpa harga jual atau barcode (product.incomplete_data);
 *  3. piutang yang jatuh tempo (receivable.due);
 *  4. backup terakhir terlalu lama (backup.overdue);
 *  5. operasi sinkronisasi yang lama belum terkirim (sync.backlog, §11.5);
 *  6. operasi sinkronisasi yang ditolak server (sync.failed);
 *  7. perangkat terdaftar yang lama tidak menyapa server (device.stale);
 *  8. transaksi menunggu verifikasi pembayaran (payment.awaiting_verification);
 *  9. selisih stok hasil opname (stock.discrepancy, FR-08).
 *
 * Deduplikasi: alert baru hanya dibuat bila tidak ada alert dengan
 * `deduplication_key` yang sama dan masih belum dibaca — jadi kondisi yang
 * bertahan tidak memicu notifikasi berulang, tapi kondisi yang muncul KEMBALI
 * setelah alert dibaca tetap bisa dibunyikan lagi.
 *
 * Ketika `$tenantId` diberikan secara eksplisit, generator bersifat
 * deterministik (CLI/scheduler/test: auto-scope tenant dimatikan dan filter
 * eksplisit dipakai). Tanpa `$tenantId`, generator memakai konteks request.
 */
class AlertGenerator
{
    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?BackupManager $backups = null,
    ) {}

    /**
     * Jalankan semua kategori alert.
     *
     * @return int Jumlah alert baru yang dibuat.
     */
    public function run(): int
    {
        return $this->stockLow()
            + $this->incompleteProducts()
            + $this->receivablesDue()
            + $this->backupOverdue()
            + $this->syncBacklog()
            + $this->syncFailed()
            + $this->staleDevices()
            + $this->paymentsAwaitingVerification()
            + $this->stockDiscrepancies();
    }

    public function stockLow(): int
    {
        $products = $this->tenantQuery(Product::class)
            ->where('is_unlimited_stock', false)
            ->where('min_stock', '>', 0)
            ->whereColumn('stock', '<=', 'min_stock')
            ->get();

        $created = 0;

        foreach ($products as $product) {
            $created += $this->createIfNew(
                Alert::TYPE_STOCK_LOW,
                "stock.low:{$product->id}",
                Alert::SEVERITY_WARNING,
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'stock' => $product->stock,
                    'min_stock' => $product->min_stock,
                    'action' => 'Sesuaikan stok',
                    'target' => '/products',
                ],
                $product->tenant_id,
            );
        }

        return $created;
    }

    public function incompleteProducts(): int
    {
        $products = $this->tenantQuery(Product::class)
            ->where(function (Builder $query) {
                $query->whereNull('price')
                    ->orWhere('price', '<=', 0)
                    ->orWhereNull('barcode')
                    ->orWhere('barcode', '');
            })
            ->get();

        $created = 0;

        foreach ($products as $product) {
            $created += $this->createIfNew(
                Alert::TYPE_PRODUCT_INCOMPLETE_DATA,
                "product.incomplete:{$product->id}",
                Alert::SEVERITY_WARNING,
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'has_price' => $product->price !== null && (int) $product->price > 0,
                    'has_barcode' => ! empty($product->barcode),
                    'action' => 'Lengkapi data produk',
                    'target' => '/products',
                ],
                $product->tenant_id,
            );
        }

        return $created;
    }

    public function receivablesDue(): int
    {
        $receivables = $this->tenantQuery(Receivable::class)
            ->where('status', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->get();

        $created = 0;

        foreach ($receivables as $receivable) {
            $created += $this->createIfNew(
                Alert::TYPE_RECEIVABLE_DUE,
                "receivable.due:{$receivable->id}",
                Alert::SEVERITY_CRITICAL,
                [
                    'receivable_id' => $receivable->uuid,
                    'customer_id' => $receivable->customer_id,
                    'remaining_minor' => $receivable->remaining_minor,
                    'due_at' => $receivable->due_at?->toIso8601String(),
                    'action' => 'Buka pembayaran piutang',
                    'target' => '/customers',
                ],
                $receivable->tenant_id,
            );
        }

        return $created;
    }

    public function backupOverdue(): int
    {
        $latest = ($this->backups ?? BackupManager::forDefault())->latest();

        // Belum pernah backup bukan kategori "terlalu lama" (anti-noise).
        if ($latest === null) {
            return 0;
        }

        $maxAgeDays = (int) config('pos.backup.max_age_days', 7);

        if (! Carbon::parse($latest->createdAt)->lt(now()->subDays($maxAgeDays))) {
            return 0;
        }

        $tenantId = $this->tenantId ?? app(TenantContext::class)->id();

        if ($tenantId === null) {
            return 0;
        }

        return $this->createIfNew(
            Alert::TYPE_BACKUP_OVERDUE,
            'backup.overdue',
            Alert::SEVERITY_WARNING,
            [
                'last_backup_at' => $latest->createdAt,
                'max_age_days' => $maxAgeDays,
                'action' => 'Buat backup sekarang',
                'target' => '/reports',
            ],
            $tenantId,
        );
    }

    /**
     * Operasi outbox yang sudah lama menunggu giliran dikirim, dikelompokkan
     * per perangkat (§11.5). Konflik/gagal permanen ditangani kategori lain
     * agar kasir tahu bedanya "belum sempat terkirim" vs "ditolak server".
     */
    public function syncBacklog(): int
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return 0;
        }

        $cutoff = now()->subMinutes((int) config('pos.alerts.sync_backlog_minutes', 30));

        $rows = SyncOutbox::query()
            ->where('tenant_id', $tenantId)
            ->where('sync_status', SyncStatus::Pending->value)
            ->where('created_at', '<=', $cutoff)
            ->selectRaw('device_id, COUNT(*) as total, MIN(created_at) as oldest')
            ->groupBy('device_id')
            ->get();

        $devices = $this->deviceNames($tenantId);
        $created = 0;

        foreach ($rows as $row) {
            $deviceId = $row->device_id;

            if ($deviceId === null) {
                continue;
            }

            $created += $this->createIfNew(
                Alert::TYPE_SYNC_BACKLOG,
                "sync.backlog:{$deviceId}",
                Alert::SEVERITY_WARNING,
                [
                    'device_id' => $deviceId,
                    'device_name' => $devices[$deviceId] ?? $deviceId,
                    'pending_count' => (int) $row->total,
                    'oldest_at' => $row->oldest,
                    'action' => 'Buka sinkronisasi',
                    'target' => '/sync',
                ],
                $tenantId,
                $deviceId,
            );
        }

        return $created;
    }

    /**
     * Operasi yang ditolak server dan butuh tindakan pengguna (§11.5, §17.4):
     * dikelompokkan per perangkat, satu alert hingga dibaca.
     */
    public function syncFailed(): int
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return 0;
        }

        $rows = SyncOutbox::query()
            ->where('tenant_id', $tenantId)
            ->where('sync_status', SyncStatus::Failed->value)
            ->selectRaw('device_id, COUNT(*) as total, MAX(updated_at) as last_at')
            ->groupBy('device_id')
            ->get();

        $devices = $this->deviceNames($tenantId);
        $created = 0;

        foreach ($rows as $row) {
            $deviceId = $row->device_id;

            if ($deviceId === null) {
                continue;
            }

            $created += $this->createIfNew(
                Alert::TYPE_SYNC_FAILED,
                "sync.failed:{$deviceId}",
                Alert::SEVERITY_CRITICAL,
                [
                    'device_id' => $deviceId,
                    'device_name' => $devices[$deviceId] ?? $deviceId,
                    'failed_count' => (int) $row->total,
                    'last_failed_at' => $row->last_at,
                    'action' => 'Tinjau operasi gagal',
                    'target' => '/sync',
                ],
                $tenantId,
                $deviceId,
            );
        }

        return $created;
    }

    /**
     * Perangkat aktif yang lama tidak menyapa server. Perangkat yang baru
     * dibuat dikecualikan agar pendaftaran baru tidak langsung memicu alert
     * (anti-noise).
     */
    public function staleDevices(): int
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return 0;
        }

        $cutoff = now()->subHours((int) config('pos.alerts.device_stale_hours', 24));

        $devices = Device::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('revoked_at')
            ->where('created_at', '<=', $cutoff)
            ->where(function (Builder $query) use ($cutoff) {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', $cutoff);
            })
            ->get();

        $created = 0;

        foreach ($devices as $device) {
            $created += $this->createIfNew(
                Alert::TYPE_DEVICE_STALE,
                "device.stale:{$device->id}",
                Alert::SEVERITY_WARNING,
                [
                    'device_id' => $device->id,
                    'device_name' => $device->device_name,
                    'platform' => $device->platform,
                    'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                    'stale_hours' => (int) config('pos.alerts.device_stale_hours', 24),
                    'action' => 'Tinjau perangkat',
                    'target' => '/sync',
                ],
                $tenantId,
                $device->id,
            );
        }

        return $created;
    }

    /**
     * Transaksi yang masih menunggu verifikasi pembayaran melewati ambang
     * (FR-05): kasir perlu memeriksa status di penyedia/gateway.
     */
    public function paymentsAwaitingVerification(): int
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return 0;
        }

        $cutoff = now()->subMinutes((int) config('pos.alerts.payment_verification_minutes', 30));

        $transactions = $this->tenantQuery(Transaction::class)
            ->where('payment_verification', PaymentVerification::AwaitingVerification->value)
            ->where('payment_status', '!=', Transaction::PAYMENT_STATUS_VOID)
            ->where('created_at', '<=', $cutoff)
            ->get();

        $created = 0;

        foreach ($transactions as $transaction) {
            $created += $this->createIfNew(
                Alert::TYPE_PAYMENT_UNVERIFIED,
                "payment.unverified:{$transaction->id}",
                Alert::SEVERITY_CRITICAL,
                [
                    'transaction_id' => $transaction->uuid,
                    'invoice_number' => $transaction->invoice_number,
                    'payment_method' => $transaction->payment_method,
                    'total_minor' => $transaction->total_minor,
                    'action' => 'Periksa verifikasi pembayaran',
                    'target' => '/transactions',
                ],
                $transaction->tenant_id,
            );
        }

        return $created;
    }

    /**
     * Selisih stok dari opname (FR-08) yang belum dijelaskan. `stock_opnames`
     * tidak memiliki `tenant_id`, jadi kepemilikan disaring lewat produk.
     */
    public function stockDiscrepancies(): int
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return 0;
        }

        $cutoff = now()->subDays((int) config('pos.alerts.stock_discrepancy_days', 7));

        $opnames = StockOpname::query()
            ->with(['product' => fn ($query) => $query->withoutGlobalScope('tenant')])
            ->where('difference', '!=', 0)
            ->where('created_at', '>=', $cutoff)
            ->whereHas('product', function (Builder $query) use ($tenantId) {
                $query->withoutGlobalScope('tenant')
                    ->where('products.tenant_id', $tenantId);
            })
            ->orderByDesc('created_at')
            ->get();

        $created = 0;
        $seen = [];

        foreach ($opnames as $opname) {
            $product = $opname->product;

            if ($product === null || isset($seen[$product->id])) {
                continue;
            }

            $seen[$product->id] = true;

            $created += $this->createIfNew(
                Alert::TYPE_STOCK_DISCREPANCY,
                "stock.discrepancy:{$product->id}",
                Alert::SEVERITY_WARNING,
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'system_stock' => $opname->system_stock,
                    'actual_stock' => $opname->actual_stock,
                    'difference' => $opname->difference,
                    'reason' => $opname->reason,
                    'action' => 'Periksa selisih stok',
                    'target' => '/products',
                ],
                $tenantId,
            );
        }

        return $created;
    }

    /**
     * @param  class-string  $model
     */
    private function tenantQuery(string $model): Builder
    {
        $query = $model::query();

        if ($this->tenantId !== null) {
            $query->withoutGlobalScope('tenant')
                ->where($query->getModel()->getTable().'.tenant_id', $this->tenantId);
        }

        return $query;
    }

    /**
     * Tenant eksplisit, atau konteks request bila tidak diberikan. Kategori
     * berbasis perangkat memakai hasil ini secara eksplisit agar tidak pernah
     * bocor antar-tenant saat konteks kosong (fail-closed).
     */
    private function resolveTenantId(): ?string
    {
        $id = $this->tenantId ?? app(TenantContext::class)->id();

        return $id !== null ? (string) $id : null;
    }

    /**
     * Peta `device_id` → nama untuk tenant, dipakai pada payload alert.
     *
     * @return array<string, string>
     */
    private function deviceNames(string $tenantId): array
    {
        return Device::query()
            ->where('tenant_id', $tenantId)
            ->pluck('device_name', 'id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return int 1 bila alert baru dibuat, 0 bila sudah ada yang belum dibaca.
     */
    private function createIfNew(
        string $type,
        string $key,
        string $severity,
        array $payload,
        ?string $tenantId,
        ?string $deviceId = null,
    ): int {
        $active = $this->tenantQuery(Alert::class)
            ->where('deduplication_key', $key)
            ->whereNull('read_at')
            ->exists();

        if ($active) {
            return 0;
        }

        Alert::create([
            'tenant_id' => $tenantId,
            'device_id' => $deviceId,
            'alert_type' => $type,
            'deduplication_key' => $key,
            'severity' => $severity,
            'payload' => $payload,
        ]);

        return 1;
    }
}
