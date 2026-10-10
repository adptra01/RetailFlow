<?php

namespace App\Support\Alerts;

use App\Backup\BackupManager;
use App\Models\Alert;
use App\Models\Product;
use App\Models\Receivable;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menghasilkan alert lokal (FR-10) dari kondisi yang bisa dilihat data lokal:
 *  1. stok di bawah ambang minimum (stock.low);
 *  2. produk tanpa harga jual atau barcode (product.incomplete_data);
 *  3. piutang yang jatuh tempo (receivable.due);
 *  4. backup terakhir terlalu lama (backup.overdue, FR-10 daftar alert).
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
            + $this->backupOverdue();
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
     * @param  array<string, mixed>  $payload
     * @return int 1 bila alert baru dibuat, 0 bila sudah ada yang belum dibaca.
     */
    private function createIfNew(
        string $type,
        string $key,
        string $severity,
        array $payload,
        ?string $tenantId,
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
            'alert_type' => $type,
            'deduplication_key' => $key,
            'severity' => $severity,
            'payload' => $payload,
        ]);

        return 1;
    }
}
