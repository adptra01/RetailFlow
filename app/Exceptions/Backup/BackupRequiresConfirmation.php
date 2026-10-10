<?php

namespace App\Exceptions\Backup;

/**
 * Restore menolak menimpa database aktif tanpa konfirmasi eksplisit.
 *
 * FR-11: "Restore tidak boleh menimpa database aktif tanpa konfirmasi dan
 * prosedur keselamatan".
 */
class BackupRequiresConfirmation extends BackupException
{
    protected int $status = 422;
}
