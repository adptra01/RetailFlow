<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncIdentity;
use App\Models\Concerns\JournalSyncChanges;
use Database\Factories\TransactionPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tender (satu kali serah-bayar) pada sebuah penjualan (FR-05
 * "Pembayaran campuran").
 *
 * Baris ini HANYA dibuat oleh aksi domain `CreateSale`; kasir tidak pernah
 * menulisnya langsung. Jumlah seluruh `amount_minor` untuk sebuah transaksi
 * sama dengan `transactions.paid_minor`, dan kembalian hanya boleh berasal dari
 * baris bertipe tunai.
 *
 * `payment_method` sengaja tidak di-cast ke enum: nilai di luar katalog (data
 * impor lama) harus tetap terbaca. Gunakan `PaymentMethod::labelFor()` di
 * lapisan tampilan.
 */
class TransactionPayment extends Model
{
    /** @use HasFactory<TransactionPaymentFactory> */
    use BelongsToTenant, HasFactory, HasSyncIdentity, JournalSyncChanges;

    protected $attributes = [
        'version' => 1,
        'payment_verification' => 'cashier_recorded',
    ];

    protected $fillable = [
        'transaction_id', 'payment_method', 'amount_minor',
        'payment_verification', 'reference', 'notes', 'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isCash(): bool
    {
        return $this->payment_method === PaymentMethod::Cash->value;
    }
}
