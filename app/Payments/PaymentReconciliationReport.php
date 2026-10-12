<?php

namespace App\Payments;

/**
 * Ringkasan satu putaran rekonsiliasi pembayaran (plan §14).
 *
 * Angka-angka ini dihitung dari `payment_reconciliation_entries` yang ditulis
 * selama putaran; `mismatches` memuat entri yang TIDAK diterapkan (transisi
 * tidak sah) agar ada yang memeriksa secara manual — rekonsiliasi tidak pernah
 * diam-diam mengubah/menolak status pembayaran.
 */
final readonly class PaymentReconciliationReport
{
    /**
     * @param  list<array<string, mixed>>  $mismatches
     */
    public function __construct(
        public string $runId,
        public ?string $provider,
        public ?string $tenantId,
        public int $polled,
        public int $changed,
        public int $unchanged,
        public int $timeout,
        public int $skipped,
        public array $mismatches = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'provider' => $this->provider,
            'tenant_id' => $this->tenantId,
            'polled' => $this->polled,
            'changed' => $this->changed,
            'unchanged' => $this->unchanged,
            'timeout' => $this->timeout,
            'skipped' => $this->skipped,
            'mismatches' => $this->mismatches,
        ];
    }
}
