<?php

namespace App\Exceptions\Backup;

/**
 * Versi skema backup lebih baru dari versi aplikasi saat ini — memulihkannya
 * ke database aktif berisiko korup/tidak konsisten (plan §21).
 */
class BackupSchemaMismatch extends BackupException
{
    protected int $status = 422;
}
