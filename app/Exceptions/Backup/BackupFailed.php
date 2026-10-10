<?php

namespace App\Exceptions\Backup;

/**
 * Kegagalan operasional backup/restore (driver, IO, integritas pasca-restore).
 */
class BackupFailed extends BackupException
{
    protected int $status = 500;
}
