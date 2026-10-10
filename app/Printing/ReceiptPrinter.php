<?php

namespace App\Printing;

use App\Exceptions\Printing\PrinterException;
use App\Models\Transaction;

/**
 * Port pencetakan struk (plan §10.4 "Printer", §20).
 *
 * Implementasi konkret (ESC/POS, driver OS, dsb.) masuk di lapisan
 * infrastruktur. Pencetakan SELALU dilakukan setelah transaksi committed —
 * kegagalan printer tidak boleh membatalkan penjualan (§17.3, §20).
 */
interface ReceiptPrinter
{
    /**
     * Cetak struk untuk transaksi yang sudah tersimpan.
     *
     * @throws PrinterException bila pencetakan gagal
     */
    public function print(Transaction $transaction): void;
}
