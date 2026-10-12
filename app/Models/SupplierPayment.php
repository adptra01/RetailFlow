<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\SupplierPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierPayment extends Model
{
    /** @use HasFactory<SupplierPaymentFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'supplier_id', 'purchase_order_id', 'amount_minor', 'payment_date',
        'payment_method', 'notes', 'user_id', 'location_id', 'device_id', 'client_request_id', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'payment_date' => 'date',
            'version' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function amount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->amount_minor / 100,
        );
    }
}
