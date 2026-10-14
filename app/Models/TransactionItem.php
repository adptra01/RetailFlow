<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use App\Support\Money\Money;
use Database\Factories\TransactionItemFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    /** @use HasFactory<TransactionItemFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'transaction_id', 'product_id', 'product_unit_id', 'unit_name',
        'quantity', 'tenant_id',
        'unit_price_minor', 'discount_minor', 'subtotal_minor',
        // FR-06: snapshot promosi per baris.
        'promotion_id', 'promotion_name',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'promotion_id' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * Alias baca satuan major (§17.1) — turunan dari `*_minor`, bukan kolom.
     */
    public function unitPrice(): Attribute
    {
        return Attribute::make(get: fn () => (new Money((int) $this->unit_price_minor))->major());
    }

    public function subtotal(): Attribute
    {
        return Attribute::make(get: fn () => (new Money((int) $this->subtotal_minor))->major());
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
