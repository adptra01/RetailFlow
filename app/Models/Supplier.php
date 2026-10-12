<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges, SoftDeletes;

    protected $attributes = ['version' => 1];

    protected $fillable = ['name', 'slug', 'contact_person', 'phone', 'email', 'address', 'description', 'balance_minor', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Supplier $supplier) {
            if (empty($supplier->slug)) {
                $supplier->slug = Str::slug($supplier->name);
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function balance(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balance_minor / 100,
        );
    }
}
