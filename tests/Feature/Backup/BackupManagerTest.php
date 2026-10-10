<?php

namespace Tests\Feature\Backup;

use App\Backup\BackupManager;
use App\Backup\Drivers\SqliteBackupDriver;
use App\Exceptions\Backup\BackupCorrupted;
use App\Exceptions\Backup\BackupNotFound;
use App\Exceptions\Backup\BackupRequiresConfirmation;
use App\Exceptions\Backup\BackupSchemaMismatch;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Facade;
use PDO;
use Tests\TestCase;

class BackupManagerTest extends TestCase
{
    private string $backupDir;

    private string $dbFile;

    private BackupManager $manager;

    private SqliteBackupDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_dir(storage_path('app/testing'))) {
            mkdir(storage_path('app/testing'), 0775, true);
        }

        $this->backupDir = storage_path('app/testing/backups-'.uniqid());
        $this->dbFile = storage_path('app/testing/db-'.uniqid().'.sqlite');
        $this->seedItems($this->dbFile);

        $this->driver = new SqliteBackupDriver($this->dbFile);
        $this->manager = new BackupManager($this->driver, $this->backupDir, '0.4.0');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile.'-wal', $this->dbFile.'-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach (glob($this->backupDir.'/**/*', GLOB_NOSORT) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach (glob($this->backupDir.'/*', GLOB_NOSORT) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
            if (is_dir($file)) {
                @rmdir($file);
            }
        }
        @rmdir($this->backupDir);

        parent::tearDown();
    }

    public function test_create_writes_dump_metadata_sidecar_and_manifest(): void
    {
        $info = $this->manager->create();

        $this->assertFileExists($info->path);
        $this->assertSame('0.4.0', $info->schemaVersion);
        $this->assertSame('sqlite', $info->driver);
        $this->assertFalse($info->encrypted);
        $this->assertSame((int) filesize($info->path), $info->size);
        $this->assertStringStartsWith('sha256:', $info->checksum);

        // Dump adalah database sqlite dengan data sumber (snapshot konsisten).
        $this->assertSame(
            ['Apel', 'Mangga'],
            $this->rows($info->path),
        );

        // Sidecar metadata untuk validasi restore.
        $sidecar = $this->backupDir.'/'.$info->filename.'.meta.json';
        $this->assertFileExists($sidecar);
        $metadata = json_decode((string) file_get_contents($sidecar), true);
        $this->assertSame($info->checksum, $metadata['checksum']);
        $this->assertSame('0.4.0', $metadata['schema_version']);

        // Manifest mencatat waktu backup terakhir (FR-11, §23).
        $latest = $this->manager->latest();
        $this->assertNotNull($latest);
        $this->assertSame($info->filename, $latest->filename);
        $this->assertNotNull($latest->createdAt);
    }

    public function test_create_to_custom_directory_is_registered_in_manifest(): void
    {
        $external = $this->backupDir.'/usb-flash';

        $info = $this->manager->create($external);

        $this->assertStringStartsWith($external, dirname($info->path));
        $this->assertFileExists($info->path);
        $this->assertFileExists($external.'/'.$info->filename.'.meta.json');

        // Tetap terdaftar di manifest (waktu backup terakhir menyeluruh).
        $this->assertSame($info->path, $this->manager->latest()?->path);
    }

    public function test_restore_requires_explicit_confirmation(): void
    {
        $info = $this->manager->create();

        $this->expectException(BackupRequiresConfirmation::class);

        $this->manager->restore($info->filename);
    }

    public function test_restore_rejects_tampered_file(): void
    {
        $info = $this->manager->create();
        file_put_contents($info->path, (string) file_get_contents($info->path).'x');

        $this->expectException(BackupCorrupted::class);

        $this->manager->restore($info->filename, force: true);
    }

    public function test_restore_rejects_future_schema_version(): void
    {
        $info = $this->manager->create();
        $sidecar = $this->backupDir.'/'.$info->filename.'.meta.json';
        $metadata = json_decode((string) file_get_contents($sidecar), true);
        $metadata['schema_version'] = '99.0.0';
        file_put_contents($sidecar, json_encode($metadata));

        $this->expectException(BackupSchemaMismatch::class);

        $this->manager->restore($info->filename, force: true);
    }

    public function test_restore_rejects_file_without_metadata(): void
    {
        $info = $this->manager->create();
        unlink($this->backupDir.'/'.$info->filename.'.meta.json');

        $this->expectException(BackupCorrupted::class);

        $this->manager->restore($info->filename, force: true);
    }

    public function test_restore_into_new_database_with_automatic_safety_snapshot(): void
    {
        $info = $this->manager->create();

        // Target "database baru" (plan §22: "Backup dapat dipulihkan ke database baru").
        $target = storage_path('app/testing/target-'.uniqid().'.sqlite');
        $this->seedItems($target);
        $this->pdo($target)->exec("INSERT INTO items (name, stock) VALUES ('Durian', 1)");

        $restoringManager = new BackupManager(new SqliteBackupDriver($target), $this->backupDir, '0.4.0');
        $safety = $restoringManager->restore($info->filename, force: true);

        // Data target menyesuaikan isi backup.
        $this->assertSame(['Apel', 'Mangga'], $this->rows($target));

        // Snapshot keselamatan pra-restore dibuat otomatis & berisi kondisi lama.
        $this->assertStringContainsString('/pre-restore/', $safety->path);
        $this->assertSame(['Apel', 'Mangga', 'Durian'], $this->rows($safety->path));

        // Terdaftar sebagai backup terbaru.
        $this->assertSame($safety->filename, $restoringManager->latest()?->filename);
        $this->assertCount(2, $restoringManager->all());

        @unlink($target);
        @unlink($target.'-wal');
        @unlink($target.'-shm');
    }

    public function test_restore_missing_file_throws_not_found(): void
    {
        $this->expectException(BackupNotFound::class);

        $this->manager->restore('tidak-ada.dump', force: true);
    }

    public function test_latest_and_all_order_newest_first(): void
    {
        $first = $this->manager->create();
        $second = $this->manager->create();

        $this->assertSame($second->filename, $this->manager->latest()?->filename);
        $all = $this->manager->all();
        $this->assertCount(2, $all);
        $this->assertSame($second->filename, $all[0]->filename);
        $this->assertSame($first->filename, $all[1]->filename);
    }

    public function test_empty_backup_dir_has_no_latest(): void
    {
        $emptyManager = new BackupManager($this->driver, $this->backupDir.'/empty', '0.4.0');

        $this->assertNull($emptyManager->latest());
        $this->assertSame([], $emptyManager->all());

        $this->expectException(BackupNotFound::class);
        $emptyManager->restore('apa.dump', force: true);
    }

    public function test_encrypted_backup_round_trip(): void
    {
        $encryptedManager = new BackupManager($this->driver, $this->backupDir, '0.4.0', encrypt: true);

        $info = $encryptedManager->create();

        $this->assertTrue($info->encrypted);
        $this->assertStringEndsWith('.dump.enc', $info->filename);
        // Konten plaintext tidak bocor ke file tersimpan.
        $this->assertStringNotContainsString('Apel', (string) file_get_contents($info->path));

        // Restore dari backup terenkripsi ke "database baru".
        $target = storage_path('app/testing/enc-target-'.uniqid().'.sqlite');
        $this->seedItems($target);
        $targetManager = new BackupManager(new SqliteBackupDriver($target), $this->backupDir, '0.4.0', encrypt: false);

        $targetManager->restore($info->filename, force: true);

        $this->assertSame(['Apel', 'Mangga'], $this->rows($target));

        @unlink($target);
        @unlink($target.'-wal');
        @unlink($target.'-shm');
    }

    public function test_encrypted_backup_with_wrong_key_is_rejected(): void
    {
        $encryptedManager = new BackupManager($this->driver, $this->backupDir, '0.4.0', encrypt: true);
        $info = $encryptedManager->create();

        $originalEncrypter = $this->app->make('encrypter');

        try {
            // Ganti kunci APP_KEY lalu coba restore → tidak bisa didekripsi.
            $this->app->instance('encrypter', new Encrypter(
                random_bytes(32),
                'aes-256-cbc',
            ));
            Facade::clearResolvedInstances();

            $this->expectException(BackupCorrupted::class);
            $this->manager->restore($info->filename, force: true);
        } finally {
            $this->app->instance('encrypter', $originalEncrypter);
            Facade::clearResolvedInstances();
        }
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
