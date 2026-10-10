<?php

namespace App\Console\Commands;

use App\Backup\BackupManager;
use App\Exceptions\Backup\BackupException;
use Illuminate\Console\Command;

class CreateBackup extends Command
{
    protected $signature = 'pos:backup:create
        {--path= : Direktori tujuan backup (media eksternal / pilihan pengguna)}
        {--encrypt : Enkripsi backup (timpa konfigurasi POS_BACKUP_ENCRYPT)}';

    protected $description = 'Buat backup manual yang konsisten (FR-11).';

    public function handle(BackupManager $backups): int
    {
        try {
            $info = $backups->create(
                $this->option('path'),
                $this->option('encrypt') ? true : null,
            );
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup dibuat: '.$info->path);
        $this->line(
            'Ukuran: '.number_format($info->size).' byte | Driver: '.$info->driver
            .' | Skema: '.$info->schemaVersion
            .' | Enkripsi: '.($info->encrypted ? 'ya' : 'tidak'),
        );

        return self::SUCCESS;
    }
}
