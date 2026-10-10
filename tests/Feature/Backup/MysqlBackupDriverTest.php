<?php

namespace Tests\Feature\Backup;

use App\Backup\Drivers\MysqlBackupDriver;
use Tests\TestCase;

class MysqlBackupDriverTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function connection(array $overrides = []): array
    {
        return array_merge([
            'database' => 'pos',
            'username' => 'kasir',
            'password' => 'rahasia123',
            'host' => '127.0.0.1',
            'port' => 3306,
        ], $overrides);
    }

    public function test_name_is_mysql(): void
    {
        $this->assertSame('mysql', (new MysqlBackupDriver($this->connection()))->name());
    }

    public function test_dumper_uses_consistent_snapshot_and_routines(): void
    {
        $command = (new MysqlBackupDriver($this->connection()))
            ->dumper()
            ->getDumpCommand('/tmp/pos.dump', '/tmp/pos-creds.cnf');

        $this->assertStringContainsString('mariadb-dump', $command);
        // Plan §21: snapshot konsisten, bukan salinan database aktif sembarangan.
        $this->assertStringContainsString('--single-transaction', $command);
        $this->assertStringContainsString('--routines', $command);
        // Kredensial lewat file, bukan argumen yang tampil di daftar proses.
        $this->assertStringContainsString('--defaults-extra-file', $command);
        $this->assertStringNotContainsString('rahasia123', $command);
    }

    public function test_client_arguments_exclude_password(): void
    {
        $driver = new MysqlBackupDriver($this->connection(['socket' => '/run/mysqld.sock']));

        $arguments = $driver->clientArguments(['pos']);

        $this->assertSame([
            'mysql',
            '--host=127.0.0.1',
            '--port=3306',
            '--user=kasir',
            '--default-character-set=utf8mb4',
            '--socket=/run/mysqld.sock',
            'pos',
        ], $arguments);
        $this->assertStringNotContainsString('rahasia123', implode(' ', $arguments));
    }

    public function test_health_check_uses_single_client_call(): void
    {
        $arguments = (new MysqlBackupDriver($this->connection()))->clientArguments(['--execute=SELECT 1']);

        $this->assertSame(['--execute=SELECT 1'], array_slice($arguments, -1));
        $this->assertContains('mysql', $arguments);
    }
}
