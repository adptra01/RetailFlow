<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SalesReturn extends Model
{
    protected $fillable = [
        'return_number', 'transaction_id', 'customer_id',
        'total', 'reason', 'user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesReturn $return) {
            if (empty($return->return_number)) {
                $return->return_number = 'RTS-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
        ];
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
}
