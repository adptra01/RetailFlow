<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'customer_id',
        'customer_name',
        'order_number',
        'table_number',
        'subtotal_minor',
        'discount_minor',
        'total_minor',
        'paid_minor',
        'change_minor',
        'status',
        'payment_method',
        'payment_status',
        'notes',
        'user_id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'change_minor' => 'integer',
            'version' => 'integer',
        ];
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
        return $this->hasMany(OrderItem::class);
    }

    public function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->subtotal_minor / 100,
        );
    }

    public function discount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->discount_minor / 100,
        );
    }

    public function total(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->total_minor / 100,
        );
    }

    public function paid(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->paid_minor / 100,
        );
    }

    public function change(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->change_minor / 100,
        );
    }
}
