<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Customer extends Model
{
    protected $fillable = ['name', 'slug', 'phone', 'address', 'credit_limit', 'balance', 'price_tier', 'notes'];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            if (empty($customer->slug)) {
                $customer->slug = Str::slug($customer->name);
            }
        });
    }

    public function payments()
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
