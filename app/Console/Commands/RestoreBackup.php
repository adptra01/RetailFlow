<?php

namespace App\Console\Commands;

use App\Backup\BackupManager;
use App\Exceptions\Backup\BackupException;
use Illuminate\Console\Command;

class RestoreBackup extends Command
{
    protected $signature = 'pos:backup:restore
        {backup : Nama file atau path backup (absolut, atau relatif ke direktori backup)}
        {--force : Konfirmasi menimpa database aktif}';

    protected $description = 'Pulihkan backup ke database aktif (FR-11; wajib validasi + konfirmasi).';

    public function handle(BackupManager $backups): int
    {
        try {
            $safety = $backups->restore(
                $this->argument('backup'),
                (bool) $this->option('force'),
            );
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restore berhasil. Snapshot keselamatan database sebelum restore:');
        $this->line('  '.$safety->path);

        return self::SUCCESS;
    }
}
