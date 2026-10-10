<?php

namespace App\Console\Commands;

use App\Backup\BackupInfo;
use App\Backup\BackupManager;
use Illuminate\Console\Command;

class BackupStatus extends Command
{
    protected $signature = 'pos:backup:status';

    protected $description = 'Tampilkan status backup: waktu backup terakhir dan daftar (FR-11, §23).';

    public function handle(BackupManager $backups): int
    {
        $latest = $backups->latest();
        $all = $backups->all();

        $this->info('Backup terakhir: '.($latest?->createdAt ?? 'belum pernah'));
        $this->info('Jumlah backup: '.count($all));

        if ($all !== []) {
            $this->table(
                ['File', 'Dibuat', 'Ukuran', 'Driver', 'Enkripsi', 'Skema'],
                array_map(fn (BackupInfo $info) => [
                    $info->filename,
                    $info->createdAt,
                    number_format($info->size),
                    $info->driver,
                    $info->encrypted ? 'ya' : 'tidak',
                    $info->schemaVersion,
                ], $all),
            );
        }

        return self::SUCCESS;
    }
}
