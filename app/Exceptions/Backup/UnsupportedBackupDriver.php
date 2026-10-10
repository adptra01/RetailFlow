<?php

namespace App\Exceptions\Backup;

/**
 * Koneksi database aktif belum punya driver backup (FR-11; plan §11: adapter).
 */
class UnsupportedBackupDriver extends BackupException
{
    protected int $status = 500;
}
