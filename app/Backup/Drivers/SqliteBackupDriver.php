<?php

namespace App\Backup\Drivers;

use App\Backup\BackupDriver;
use App\Exceptions\Backup\BackupCorrupted;
use App\Exceptions\Backup\BackupFailed;
use PDO;
use PDOException;

/**
 * Snapshot SQLite via `VACUUM INTO` — snapshot konsisten resmi SQLite
 * (plan §21). Tidak butuh binary `sqlite3`, jadi aman di perangkat pengguna
 * (NativePHP). Restore: salin ke file temporer lalu rename atomik, bersihkan
 * sidecar `-wal`/`-shm` basi, dan verifikasi `PRAGMA integrity_check`.
 */
final class SqliteBackupDriver implements BackupDriver
{
    public function __construct(private readonly string $databasePath) {}

    public function name(): string
    {
        return 'sqlite';
    }

    public function createSnapshot(string $destination): void
    {
        if ($this->databasePath === ':memory:') {
            throw new BackupFailed('SQLite :memory: tidak dapat di-snapshot oleh backup pos; gunakan database file.');
        }

        try {
            $pdo = $this->connection($this->databasePath);
            $pdo->exec('VACUUM INTO '.$this->quote($destination));
        } catch (PDOException $e) {
            throw new BackupFailed('SQLite snapshot gagal: '.$e->getMessage(), 0, $e);
        }

        if (! is_file($destination) || filesize($destination) === 0) {
            throw new BackupFailed('SQLite snapshot tidak tertulis: '.$destination);
        }
    }

    public function restoreSnapshot(string $source): void
    {
        if ($this->databasePath === ':memory:') {
            throw new BackupFailed('Restore ke SQLite :memory: tidak didukung; sasar database file (database baru, plan §22).');
        }

        if (! $this->isHealthyFile($source)) {
            throw new BackupCorrupted('File backup bukan database SQLite yang sehat (PRAGMA integrity_check gagal).');
        }

        $temporary = $this->databasePath.'.restoring.'.bin2hex(random_bytes(4));

        if (! copy($source, $temporary)) {
            @unlink($temporary);

            throw new BackupFailed('Tidak dapat menulis database sementara untuk restore.');
        }

        if (! rename($temporary, $this->databasePath)) {
            @unlink($temporary);

            throw new BackupFailed('Tidak dapat mengganti database aktif.');
        }

        // WAL/SHM adalah pasangan file database — sidecar basi bisa korup.
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($this->databasePath.$suffix)) {
                @unlink($this->databasePath.$suffix);
            }
        }

        if (! $this->isHealthyFile($this->databasePath)) {
            throw new BackupFailed('Database hasil restore gagal pemeriksaan integritas.');
        }
    }

    public function isHealthy(): bool
    {
        return $this->isHealthyFile($this->databasePath);
    }

    private function isHealthyFile(string $path): bool
    {
        try {
            $check = $this->connection($path)
                ->query('PRAGMA integrity_check')
                ->fetchColumn();

            return $check === 'ok';
        } catch (PDOException) {
            return false;
        }
    }

    private function connection(string $path): PDO
    {
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function quote(string $path): string
    {
        return "'".str_replace("'", "''", $path)."'";
    }
}
