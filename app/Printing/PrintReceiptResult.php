<?php

namespace App\Printing;

use App\Models\Transaction;

/**
 * Hasil satu upaya pencetakan struk. Pencetakan TIDAK pernah memengaruhi
 * status transaksi; kegagalan hanya dilaporkan kembali ke pemanggil (plan
 * §17.3, §20).
 */
final readonly class PrintReceiptResult
{
    private function __construct(
        public bool $success,
        public Transaction $transaction,
        public ?string $error = null,
    ) {}

    public static function success(Transaction $transaction): self
    {
        return new self(true, $transaction);
    }

    public static function failed(Transaction $transaction, string $error): self
    {
        return new self(false, $transaction, $error);
    }
}
