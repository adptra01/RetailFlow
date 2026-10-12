<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use App\Support\Money\Money;
use Database\Factories\SalesReturnItemFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu baris barang yang dikembalikan pada retur penjualan (§17.4).
 *
 * `unit_price_minor`/`subtotal_minor` adalah nilai efektif yang dibayar
 * pelanggan untuk baris itu (snapshot dari `transaction_items`), sehingga
 * nilai retur tidak ikut berubah bila harga produk berubah kemudian.
 */
class SalesReturnItem extends Model
{
    /** @use HasFactory<SalesReturnItemFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'sales_return_id', 'transaction_item_id', 'product_id', 'product_unit_id',
        'qty', 'unit_price_minor', 'discount_minor', 'subtotal_minor', 'reason',
        // Cermin desimal transisional untuk UI pra-rebuild.
        'price', 'subtotal',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function unitPrice(): Attribute
    {
        return Attribute::make(get: fn () => (new Money($this->unit_price_minor))->major());
    }
}
