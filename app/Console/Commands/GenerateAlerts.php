<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Alerts\AlertGenerator;
use Illuminate\Console\Command;

class GenerateAlerts extends Command
{
    protected $signature = 'pos:alerts:generate';

    protected $description = 'Buat alert lokal (FR-10): stok menipis, data produk belum lengkap, piutang jatuh tempo, backup terlalu lama.';

    public function handle(): int
    {
        $created = 0;

        foreach (Tenant::query()->orderBy('created_at')->pluck('id') as $tenantId) {
            $created += (new AlertGenerator((string) $tenantId))->run();
        }

        $this->info("pos:alerts:generate: {$created} alert baru.");

        return self::SUCCESS;
    }
}
