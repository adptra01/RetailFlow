<?php

namespace App\Backup;

use App\Backup\Drivers\MysqlBackupDriver;
use App\Backup\Drivers\SqliteBackupDriver;
use App\Exceptions\Backup\UnsupportedBackupDriver;

/**
 * Pilih driver backup dari koneksi database aktif (plan §11 "Adapter
 * server database"; FR-11). SQLite untuk target native/NativePHP,
 * MySQL/MariaDB untuk web.
 */
final class BackupDriverFactory
{
    public static function forConnection(?string $connection = null): BackupDriver
    {
        $connection ??= (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);

        return match ($connection) {
            'sqlite' => new SqliteBackupDriver((string) ($config['database'] ?? ':memory:')),
            'mysql', 'mariadb' => new MysqlBackupDriver($config),
            default => throw new UnsupportedBackupDriver(
                "Koneksi '{$connection}' belum didukung backup (sqlite atau mysql)."
            ),
        };
    }
}
