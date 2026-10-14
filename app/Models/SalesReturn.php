<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use App\Support\Money\Money;
use Database\Factories\SalesReturnFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Retur penjualan (FR-08 "Retur penjualan dengan alasan", §17.4).
 *
 * Dokumen koreksi yang mencatat barang yang dikembalikan pelanggan: alasan,
 * nilai retur, koreksi piutang, dan uang yang benar-benar dikembalikan
 * (`refund_minor`). Stok hanya dikembalikan bila kebijakan retur
 * mengizinkannya (`stock_restored`).
 */
class SalesReturn extends Model
{
    /** @use HasFactory<SalesReturnFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'transaction_id', 'customer_id', 'reason',
        'subtotal_minor', 'discount_minor', 'total_minor',
        'receivable_adjustment_minor', 'refund_minor', 'stock_restored',
        'user_id', 'location_id', 'device_id', 'client_request_id', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'receivable_adjustment_minor' => 'integer',
            'refund_minor' => 'integer',
            'stock_restored' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SalesReturn $return) {
            if (empty($return->return_number)) {
                $return->return_number = 'RTS-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
            }
        });
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    /**
     * Nilai retur dalam satuan major (IDR) untuk tampilan.
     *
     * Memakai `Money` — bukan pembagian 100 tetap — karena exponent minor
     * berasal dari konfigurasi (§17.1).
     */
    public function total(): Attribute
    {
        return Attribute::make(get: fn () => (new Money($this->total_minor))->major());
    }

    public function subtotal(): Attribute
    {
        return Attribute::make(get: fn () => (new Money($this->subtotal_minor))->major());
    }

    public function discount(): Attribute
    {
        return Attribute::make(get: fn () => (new Money($this->discount_minor))->major());
    }

    public function refund(): Attribute
    {
        return Attribute::make(get: fn () => (new Money($this->refund_minor))->major());
    }
}
