<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SeedDefaultRoles extends Command
{
    protected $signature = 'pos:roles:seed';

    protected $description = 'Seed idempoten peran & izin awal FR-12 (Owner, Admin, Supervisor, Kasir, Manajer Inventori, Viewer).';

    public function handle(): int
    {
        $this->callSilently('db:seed', ['--class' => 'Database\\Seeders\\DefaultRolesSeeder', '--force' => true]);

        $this->info('Peran & izin FR-12 tersedia.');

        return self::SUCCESS;
    }
}
