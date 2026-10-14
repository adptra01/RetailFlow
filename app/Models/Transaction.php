<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use App\Support\Money\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    /** Status pembayaran yang sah (kolom `payment_status`) — §17.4, FR-04. */
    public const PAYMENT_STATUS_PAID = 'paid';

    public const PAYMENT_STATUS_CREDIT = 'credit';

    public const PAYMENT_STATUS_VOID = 'void';

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'user_id', 'customer_id', 'customer_name', 'invoice_number',
        'payment_method', 'payment_status', 'payment_verification', 'notes',
        'tenant_id', 'location_id', 'device_id', 'client_request_id',
        'subtotal_minor', 'discount_minor', 'total_minor', 'paid_minor', 'change_minor',
        // FR-06: snapshot promosi (diterima dari aksi domain CreateSale).
        'promotion_id', 'promotion_name', 'promotion_discount_minor',
        // §17.4: jejak pembatalan terkontrol (diterima dari aksi domain VoidSale).
        'voided_at', 'voided_by', 'void_reason', 'stock_restored',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'change_minor' => 'integer',
            'promotion_id' => 'integer',
            'promotion_discount_minor' => 'integer',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
            'stock_restored' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * Alias baca satuan major (§17.1) — BUKAN kolom.
     *
     * Nilai kebenaran uang penjualan hanya ada pada kolom `*_minor`. Nama lama
     * (`total_amount`, `paid_amount`, `change_amount`) dipertahankan sebagai
     * turunan baca supaya tampilan pra-rebuild tidak perlu diubah dan tidak ada
     * penulis kedua. Untuk agregat SQL, gunakan `*_minor` (accessor tidak
     * berlaku di level query builder).
     */
    public function totalAmount(): Attribute
    {
        return Attribute::make(get: fn () => (new Money((int) $this->total_minor))->major());
    }

    public function paidAmount(): Attribute
    {
        return Attribute::make(get: fn () => (new Money((int) $this->paid_minor))->major());
    }

    public function changeAmount(): Attribute
    {
        return Attribute::make(get: fn () => (new Money((int) $this->change_minor))->major());
    }

    /**
     * §17.4: transaksi yang dibatalkan tidak dihapus, hanya ditandai. Laporan
     * dan kasir harus menyaringnya lewat helper ini, bukan mencocokkan string.
     */
    public function isVoided(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS_VOID;
    }

    /**
     * Penjualan kredit (FR-07): ada saldo belum dibayar yang tercatat sebagai
     * piutang.
     */
    public function isCredit(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS_CREDIT;
    }

    /**
     * Piutang yang lahir dari penjualan ini (FR-07). Satu transaksi memiliki
     * paling banyak satu piutang.
     */
    public function receivable(): HasOne
    {
        return $this->hasOne(Receivable::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
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
