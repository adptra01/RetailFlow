<?php

namespace App\Exceptions\Backup;

/**
 * File backup yang diminta tidak ada.
 */
class BackupNotFound extends BackupException
{
    protected int $status = 404;
}
