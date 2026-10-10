<?php

namespace Tests\Feature\Backup;

use PDO;
use Tests\TestCase;

class BackupCommandsTest extends TestCase
{
    private string $backupDir;

    private string $externalDir;

    private string $dbFile;

    private string $originalDatabase;

    private string $originalBackupPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_dir(storage_path('app/testing'))) {
            mkdir(storage_path('app/testing'), 0775, true);
        }

        $this->backupDir = storage_path('app/testing/cmd-backups-'.uniqid());
        $this->externalDir = storage_path('app/testing/cmd-external-'.uniqid());
        $this->dbFile = storage_path('app/testing/cmd-db-'.uniqid().'.sqlite');
        $this->seedItems($this->dbFile);

        $this->originalDatabase = (string) config('database.connections.sqlite.database');
        $this->originalBackupPath = (string) config('pos.backup.path');

        // Perintah memakai driver & direktori yang dikontrol test, bukan
        // :memory: / lokasi produksi.
        config(['database.connections.sqlite.database' => $this->dbFile]);
        config(['pos.backup.path' => $this->backupDir]);
    }

    protected function tearDown(): void
    {
        config(['database.connections.sqlite.database' => $this->originalDatabase]);
        config(['pos.backup.path' => $this->originalBackupPath]);

        foreach ([$this->dbFile, $this->dbFile.'-wal', $this->dbFile.'-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach ([$this->backupDir, $this->externalDir] as $dir) {
            foreach (glob($dir.'/**/*', GLOB_NOSORT) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            foreach (glob($dir.'/*', GLOB_NOSORT) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
                if (is_dir($file)) {
                    @rmdir($file);
                }
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function test_create_backup_command_succeeds(): void
    {
        $this->artisan('pos:backup:create')
            ->expectsOutputToContain('Backup dibuat')
            ->assertExitCode(0);

        $this->assertCount(1, glob($this->backupDir.'/pos-backup-*.dump') ?: []);
        $this->assertFileExists($this->backupDir.'/manifest.json');
    }

    public function test_create_backup_to_external_directory(): void
    {
        $this->artisan('pos:backup:create', ['--path' => $this->externalDir])
            ->expectsOutputToContain('Backup dibuat')
            ->assertExitCode(0);

        $this->assertCount(1, glob($this->externalDir.'/pos-backup-*.dump') ?: []);
    }

    public function test_status_shows_last_backup_time(): void
    {
        $this->artisan('pos:backup:create')->assertExitCode(0);

        $this->artisan('pos:backup:status')
            ->expectsOutputToContain('Backup terakhir:')
            ->expectsOutputToContain('Jumlah backup: 1')
            ->assertExitCode(0);
    }

    public function test_restore_requires_force_flag(): void
    {
        $this->artisan('pos:backup:create')->assertExitCode(0);
        $file = basename((glob($this->backupDir.'/pos-backup-*.dump') ?: [''])[0]);

        $this->artisan('pos:backup:restore', ['backup' => $file])
            ->expectsOutputToContain('--force')
            ->assertExitCode(1);
    }

    public function test_restore_with_force_restores_database(): void
    {
        $this->artisan('pos:backup:create')->assertExitCode(0);
        $file = basename((glob($this->backupDir.'/pos-backup-*.dump') ?: [''])[0]);

        // Rusakkan database aktif (hapus tabel) lalu pulihkan dari backup.
        $this->pdo($this->dbFile)->exec('DROP TABLE items');

        $this->artisan('pos:backup:restore', ['backup' => $file, '--force' => true])
            ->expectsOutputToContain('Restore berhasil')
            ->assertExitCode(0);

        $this->assertSame(
            ['Apel', 'Mangga'],
            $this->pdo($this->dbFile)->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * @return list<string>
     */
    private function rows(string $path): array
    {
        return $this->pdo($path)
            ->query('SELECT name FROM items ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
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
