<?php

namespace App\Exceptions\Backup;

/**
 * Backup gagal validasi integritas: checksum tidak cocok, metadata hilang
 * (tidak bisa memvalidasi versi skema), atau isi bukan database yang sehat.
 *
 * FR-11: "Restore harus memvalidasi versi skema dan integritas file".
 */
class BackupCorrupted extends BackupException
{
    protected int $status = 422;
}
