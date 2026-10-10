<?php

namespace App\Backup\Drivers;

use App\Backup\BackupDriver;
use App\Exceptions\Backup\BackupFailed;
use Spatie\DbDumper\Databases\MariaDb;
use Spatie\DbDumper\Exceptions\DumpFailed;
use Symfony\Component\Process\Process;

/**
 * Snapshot MySQL/MariaDB melalui spatie/db-dumper (`mariadb-dump`) dengan
 * `--single-transaction` (snapshot konsisten per transaksi — plan §21) dan
 * `--routines`. Restore via klien `mysql` dengan kredensial lewat env
 * `MYSQL_PWD` agar tidak tampil di daftar proses.
 */
final class MysqlBackupDriver implements BackupDriver
{
    /**
     * @param  array<string, mixed>  $connection  isi `database.connections.*`
     */
    public function __construct(private readonly array $connection) {}

    public function name(): string
    {
        return 'mysql';
    }

    public function createSnapshot(string $destination): void
    {
        try {
            $this->dumper()->dumpToFile($destination);
        } catch (DumpFailed $e) {
            throw new BackupFailed('MySQL/MariaDB dump gagal: '.$e->getMessage(), 0, $e);
        }

        if (! is_file($destination) || filesize($destination) === 0) {
            throw new BackupFailed('MySQL/MariaDB dump kosong: '.$destination);
        }
    }

    public function restoreSnapshot(string $source): void
    {
        $process = new Process(
            $this->clientArguments([(string) $this->connection['database']]),
            timeout: 600,
        );

        $process->setEnv($this->clientEnv());
        $process->setInput(fopen($source, 'rb'));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new BackupFailed('SQL restore gagal: '.trim((string) ($process->getErrorOutput() ?: $process->getOutput())));
        }
    }

    public function isHealthy(): bool
    {
        $process = new Process($this->clientArguments(['--execute=SELECT 1']), timeout: 15);
        $process->setEnv($this->clientEnv());
        $process->run();

        return $process->isSuccessful();
    }

    public function dumper(): MariaDb
    {
        $dumper = MariaDb::create()
            ->setDbName((string) $this->connection['database'])
            ->setUserName((string) ($this->connection['username'] ?? 'root'))
            ->setPassword((string) ($this->connection['password'] ?? ''))
            ->setHost((string) ($this->connection['host'] ?? '127.0.0.1'))
            ->setPort((int) ($this->connection['port'] ?? 3306))
            ->useSingleTransaction()
            ->includeRoutines();

        if (! empty($this->connection['socket'])) {
            $dumper->setSocket((string) $this->connection['socket']);
        }

        return $dumper;
    }

    /**
     * Argumen klien `mysql` — tanpa password (kredensial lewat env).
     *
     * @return list<string>
     */
    public function clientArguments(array $tail = []): array
    {
        $arguments = [
            'mysql',
            '--host='.(string) ($this->connection['host'] ?? '127.0.0.1'),
            '--port='.(int) ($this->connection['port'] ?? 3306),
            '--user='.(string) ($this->connection['username'] ?? 'root'),
            '--default-character-set=utf8mb4',
        ];

        if (! empty($this->connection['socket'])) {
            $arguments[] = '--socket='.(string) $this->connection['socket'];
        }

        return [...$arguments, ...$tail];
    }

    /**
     * @return array<string, string>
     */
    private function clientEnv(): array
    {
        return ['MYSQL_PWD' => (string) ($this->connection['password'] ?? '')];
    }
}
