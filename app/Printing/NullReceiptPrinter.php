<?php

namespace App\Printing;

use App\Models\Transaction;

/**
 * Adaptor default: tidak mencetak apa pun (printer bersifat opsional; plan §20
 * "Printer tidak boleh menjadi syarat untuk menyimpan transaksi").
 *
 * Selalu sukses sehingga alur checkout tetap berjalan di lingkungan tanpa
 * perangkat keras.
 */
final class NullReceiptPrinter implements ReceiptPrinter
{
    public function print(Transaction $transaction): void
    {
        // No-op: tidak ada perangkat printer yang terpasang.
    }
}
