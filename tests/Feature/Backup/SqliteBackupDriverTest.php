<?php

namespace Tests\Feature\Backup;

use App\Backup\Drivers\SqliteBackupDriver;
use App\Exceptions\Backup\BackupCorrupted;
use App\Exceptions\Backup\BackupFailed;
use PDO;
use Tests\TestCase;

class SqliteBackupDriverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('app/testing/sqlite-driver-'.uniqid());
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_create_snapshot_is_consistent_sqlite_file(): void
    {
        $db = $this->directory.'/source.sqlite';
        $this->seedItems($db);
        $driver = new SqliteBackupDriver($db);

        $snapshot = $this->directory.'/snapshot-'.uniqid().'.dump';
        $driver->createSnapshot($snapshot);

        $this->assertFileExists($snapshot);
        $this->assertGreaterThan(0, filesize($snapshot));
        $this->assertSame('ok', $this->pdo($snapshot)->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(
            ['Apel', 'Mangga'],
            $this->pdo($snapshot)->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function test_restore_replaces_target_database_atomically(): void
    {
        $source = $this->directory.'/source.sqlite';
        $this->seedItems($source);

        $snapshot = $this->directory.'/snapshot-'.uniqid().'.dump';
        (new SqliteBackupDriver($source))->createSnapshot($snapshot);

        $target = $this->directory.'/target.sqlite';
        $this->seedItems($target);
        $this->pdo($target)->exec("INSERT INTO items (name, stock) VALUES ('Durian', 1)");

        (new SqliteBackupDriver($target))->restoreSnapshot($snapshot);

        $this->assertSame(
            ['Apel', 'Mangga'],
            $this->pdo($target)->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
        $this->assertSame('ok', $this->pdo($target)->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function test_restore_rejects_corrupted_file(): void
    {
        $garbage = $this->directory.'/garbage.dump';
        file_put_contents($garbage, 'ini bukan database sqlite');

        $this->expectException(BackupCorrupted::class);

        (new SqliteBackupDriver($this->directory.'/target.sqlite'))->restoreSnapshot($garbage);
    }

    public function test_restore_rejects_memory_database(): void
    {
        $source = $this->directory.'/source.sqlite';
        $this->seedItems($source);
        $snapshot = $this->directory.'/snapshot-'.uniqid().'.dump';
        (new SqliteBackupDriver($source))->createSnapshot($snapshot);

        $this->expectException(BackupFailed::class);

        (new SqliteBackupDriver(':memory:'))->restoreSnapshot($snapshot);
    }

    public function test_create_snapshot_rejects_memory_database(): void
    {
        $this->expectException(BackupFailed::class);

        (new SqliteBackupDriver(':memory:'))->createSnapshot($this->directory.'/x.dump');
    }

    public function test_is_healthy_reports_database_health(): void
    {
        $healthy = $this->directory.'/healthy.sqlite';
        $this->seedItems($healthy);

        $this->assertTrue((new SqliteBackupDriver($healthy))->isHealthy());

        $broken = $this->directory.'/broken.sqlite';
        file_put_contents($broken, 'rusak');

        $this->assertFalse((new SqliteBackupDriver($broken))->isHealthy());
    }

    private function seedItems(string $path): void
    {
        $pdo = $this->pdo($path);
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL, stock INTEGER DEFAULT 0)');
        $pdo->exec("INSERT INTO items (name, stock) VALUES ('Apel', 5), ('Mangga', 3)");
    }

    private function pdo(string $path): PDO
    {
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
