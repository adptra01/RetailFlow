<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Promosi diskon (FR-06): diskon persen/nominal untuk produk tertentu atau
 * seluruh transaksi, berlaku pada periode tertentu.
 *
 * Aturan penggabungan (didokumentasikan di `EvaluatePromotions`): satu promosi
 * TERBAIK per produk + satu promosi transaksi TERBAIK; promosi produk dan
 * transaksi boleh bergabung; dalam satu lapisan hanya yang terbaik yang
 * dipakai. Konfigurasi promosi yang berubah tidak pernah mengubah transaksi
 * historis karena nilai diskon di-snapshot saat penjualan (kolom
 * `promotions_*` di transactions / transaction_items).
 */
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'name', 'code', 'discount_type', 'discount_value', 'scope',
        'min_purchase_minor', 'max_discount_minor', 'starts_at', 'ends_at',
        'is_active', 'priority', 'notes', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'min_purchase_minor' => 'integer',
            'max_discount_minor' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'version' => 'integer',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    /**
     * Promosi yang berlaku pada waktu tertentu: aktif, belum lewat, dalam
     * periode (`starts_at`/`ends_at`).
     */
    public function scopeActiveAt(Builder $query, Carbon|string|null $at = null): Builder
    {
        $at = $at instanceof Carbon ? $at : Carbon::parse($at ?? 'now');

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    public function isPercent(): bool
    {
        return $this->discount_type === 'percent';
    }

    public function isNominal(): bool
    {
        return $this->discount_type === 'nominal';
    }

    public function isProductScoped(): bool
    {
        return $this->scope === 'product';
    }

    public function isTransactionScoped(): bool
    {
        return $this->scope === 'transaction';
    }

    /**
     * Nilai diskon untuk subtotal tertentu (minor) dengan aturan FR-06:
     * - tidak pernah negatif;
     * - tidak melebihi subtotal yang didiskon;
     * - dibatasi `max_discount_minor` bila diisi (cap).
     */
    public function discountFor(int $subtotalMinor): int
    {
        $minor = $this->isPercent()
            ? (int) round($subtotalMinor * max(0, $this->discount_value) / 100)
            : max(0, (int) $this->discount_value);

        $minor = min($minor, max(0, $subtotalMinor));

        if ($this->max_discount_minor !== null) {
            $minor = min($minor, max(0, (int) $this->max_discount_minor));
        }

        return max(0, $minor);
    }

    /**
     * Label ringkas untuk UI, mis. "Diskon 10%", "Diskon Rp5.000",
     * "Min. belanja Rp50.000".
     */
    public function label(): string
    {
        $value = $this->isPercent() ? "{$this->discount_value}%" : number_format((int) $this->discount_value, 0, ',', '.');

        return __('Diskon :value', ['value' => $value]);
    }
}
