<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'user_id', 'customer_id', 'customer_name', 'invoice_number', 'total_amount',
        'paid_amount', 'change_amount', 'payment_method', 'payment_status', 'payment_verification', 'notes',
        'tenant_id', 'location_id', 'device_id', 'client_request_id',
        'subtotal_minor', 'discount_minor', 'total_minor', 'paid_minor', 'change_minor',
        // FR-06: snapshot promosi (diterima dari aksi domain CreateSale).
        'promotion_id', 'promotion_name', 'promotion_discount_minor',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'float',
            'paid_amount' => 'float',
            'change_amount' => 'float',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'change_minor' => 'integer',
            'promotion_id' => 'integer',
            'promotion_discount_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * Tender pembayaran (FR-05). Satu baris = satu kali serah-bayar; penjualan
     * metode tunggal pun punya satu baris agar laporan per metode punya satu
     * sumber. Lihat `TransactionPayment`.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(TransactionPayment::class);
    }

    /**
     * True bila penjualan dibayar dengan lebih dari satu tender. Kolom
     * `payment_method` juga bernilai "mixed" pada kasus ini; helper ini dipakai
     * tampilan agar tidak bergantung pada nilai string mentah.
     */
    public function isSplitPayment(): bool
    {
        return $this->payments()->count() > 1;
    }
}
