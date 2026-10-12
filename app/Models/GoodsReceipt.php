<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'purchase_order_id', 'user_id', 'notes',
        'location_id', 'device_id', 'client_request_id', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (GoodsReceipt $gr) {
            if (empty($gr->receipt_number)) {
                $gr->receipt_number = 'GR-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
            }
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
