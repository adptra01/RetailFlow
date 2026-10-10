<?php

namespace App\Backup;

use App\Exceptions\Backup\BackupCorrupted;
use App\Exceptions\Backup\BackupFailed;

/**
 * Kontrak adapter snapshot database (plan §11: "Adapter server database").
 *
 * Driver harus menghasilkan SNAPSHOT YANG KONSISTEN (plan §21: "Backup tidak
 * boleh menyalin database aktif secara sembarangan ketika transaksi sedang
 * berlangsung"):
 *  - SQLite → `VACUUM INTO` (snapshot konsisten resmi SQLite; tanpa binary
 *    eksternal sehingga aman di perangkat NativePHP);
 *  - MySQL/MariaDB → `mariadb-dump --single-transaction` via spatie/db-dumper.
 */
interface BackupDriver
{
    public function name(): string;

    /**
     * Tulis snapshot konsisten database saat ini ke file tujuan.
     *
     * @throws BackupFailed
     */
    public function createSnapshot(string $destination): void;

    /**
     * Pulihkan database dari file snapshot (setelah validasi dilakukan manager).
     *
     * @throws BackupCorrupted
     * @throws BackupFailed
     */
    public function restoreSnapshot(string $source): void;

    /**
     * Pemeriksaan kesehatan database (dipakai pasca-restore; plan §21:
     * "Restore harus memeriksa integritas database").
     */
    public function isHealthy(): bool;
}
