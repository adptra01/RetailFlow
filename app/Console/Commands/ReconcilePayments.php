<?php

namespace App\Console\Commands;

use App\Actions\Payments\ReconcilePaymentCharges;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'pos:reconcile
        {--provider= : Kunci penyedia tertentu (default: semua penyedia yang memiliki charge belum final)}
        {--tenant= : Batasi ke satu tenant}
        {--limit=500 : Jumlah charge maksimum per putaran}';

    protected $description = 'Rekonsiliasi pembayaran (plan §14): cek ulang status charge yang belum final ke penyedia, terapkan perubahan yang sah, dan laporkan penyimpangan untuk pemeriksaan manual.';

    public function handle(ReconcilePaymentCharges $action): int
    {
        $report = $action->handle([
            'provider' => $this->option('provider') ?: null,
            'tenantId' => $this->option('tenant') ?: null,
            'limit' => (int) ($this->option('limit') ?: 500),
        ]);

        $this->info("Rekonsiliasi ({$report->runId}) selesai.");
        $this->line(sprintf(
            'Dipoll: %d | Berubah: %d | Sama: %d | Tidak dijawab: %d | Dilewati: %d',
            $report->polled,
            $report->changed,
            $report->unchanged,
            $report->timeout,
            $report->skipped,
        ));

        if ($report->mismatches !== []) {
            $this->warn('Penyimpangan — perlu pemeriksaan manual:');

            $this->table(
                ['Charge', 'Referensi', 'Transaksi', 'Alasan'],
                array_map(
                    static fn (array $mismatch): array => [
                        $mismatch['payment_charge_id'],
                        $mismatch['provider_reference'],
                        $mismatch['transaction_id'] ?? '-',
                        (string) $mismatch['reason'],
                    ],
                    $report->mismatches,
                ),
            );
        }

        return self::SUCCESS;
    }
}
