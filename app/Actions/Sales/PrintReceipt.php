<?php

namespace App\Actions\Sales;

use App\Audit\AuditRecorder;
use App\Enums\AuditAction;
use App\Exceptions\Printing\PrinterException;
use App\Models\Transaction;
use App\Models\User;
use App\Printing\PrintReceiptResult;
use App\Printing\ReceiptPrinter;
use Illuminate\Support\Facades\Log;

/**
 * Use case cetak struk (plan §10.4, §17.3, §20).
 *
 * Prinsip: pencetakan berjalan SETELAH transaksi committed. Kegagalan printer
 * tidak pernah me-rollback penjualan — hanya dicatat (log + audit) dan
 * dilaporkan lewat `PrintReceiptResult`, sehingga kasir dapat mencetak ulang
 * atau menyimpan struk (§20 "Sediakan struk yang dapat disimpan atau dicetak
 * ulang").
 */
final class PrintReceipt
{
    private readonly AuditRecorder $audit;

    public function __construct(
        private readonly ReceiptPrinter $printer,
        ?AuditRecorder $audit = null,
    ) {
        $this->audit = $audit ?? new AuditRecorder;
    }

    public function handle(Transaction $transaction, ?User $actor = null): PrintReceiptResult
    {
        try {
            $this->printer->print($transaction);
        } catch (PrinterException $e) {
            Log::warning('Gagal mencetak struk; penjualan tetap tersimpan.', [
                'transaction' => $transaction->uuid,
                'invoice_number' => $transaction->invoice_number,
                'error' => $e->getMessage(),
            ]);

            $this->audit->record(
                AuditAction::ReceiptPrintFailed,
                $actor,
                entityType: 'sale',
                entityId: $transaction->uuid,
                reason: $e->getMessage(),
                metadata: ['invoice_number' => $transaction->invoice_number],
            );

            return PrintReceiptResult::failed($transaction, $e->getMessage());
        }

        return PrintReceiptResult::success($transaction);
    }
}
