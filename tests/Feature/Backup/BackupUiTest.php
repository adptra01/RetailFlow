<?php

namespace Tests\Feature\Backup;

use App\Backup\BackupManager;
use App\Backup\Drivers\SqliteBackupDriver;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PDO;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 3 item 4 — UI backup dan restore (FR-11).
 *
 * Halaman hanya boleh diakses pemegang izin `backup.restore` (Owner),
 * menampilkan waktu backup terakhir, membuat backup baru, dan memulihkan
 * database setelah konfirmasi eksplisit (mengetik RESTORE).
 *
 * BackupManager di-bind ke database SQLite uji + direktori uji sehingga
 * test tidak menyentuh database aplikasi maupun berkas backup sungguhan.
 */
class BackupUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $backupDir;

    private string $dbFile;

    private BackupManager $manager;

    private Tenant $tenant;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Flux memakai @teleport → komponen multi-akar; matikan debug.
        config(['app.debug' => false]);

        if (! is_dir(storage_path('app/testing'))) {
            mkdir(storage_path('app/testing'), 0775, true);
        }

        $this->backupDir = storage_path('app/testing/backups-ui-'.uniqid());
        $this->dbFile = storage_path('app/testing/db-ui-'.uniqid().'.sqlite');

        $this->seedItems($this->dbFile);

        $this->manager = new BackupManager(
            new SqliteBackupDriver($this->dbFile),
            $this->backupDir,
            (string) config('pos.schema_version', '0.4.0'),
        );

        $this->app->instance(BackupManager::class, $this->manager);
        config(['pos.backup.path' => $this->backupDir]);

        $this->tenant = Tenant::factory()->create();

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole('cashier');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile.'-wal', $this->dbFile.'-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Berkas di dalam subdirektori (mis. `pre-restore/`) lalu berkas root.
        foreach (glob($this->backupDir.'/*/*', GLOB_NOSORT) ?: [] as $file) {
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

    public function test_guests_are_redirected_from_the_backup_page(): void
    {
        $this->get(route('backup.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_restore_permission_is_forbidden(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('backup.index'))
            ->assertForbidden();
    }

    public function test_owner_sees_status_and_backup_needed_warning_when_empty(): void
    {
        $this->actingAs($this->owner)
            ->get(route('backup.index'))
            ->assertOk()
            ->assertSee('Backup & Restore')
            ->assertSee('Backup perlu dibuat')
            ->assertSee('Belum ada');
    }

    public function test_owner_can_create_a_backup_from_the_ui(): void
    {
        Livewire::actingAs($this->owner)
            ->test('backup.index')
            ->call('createBackup');

        $all = $this->manager->all();

        $this->assertCount(1, $all);
        $this->assertFileExists($all[0]->path);
        $this->assertFalse($all[0]->encrypted);
    }

    public function test_encrypt_toggle_produces_an_encrypted_backup(): void
    {
        Livewire::actingAs($this->owner)
            ->test('backup.index')
            ->set('encrypt', true)
            ->call('createBackup');

        $all = $this->manager->all();

        $this->assertCount(1, $all);
        $this->assertTrue($all[0]->encrypted);
        $this->assertStringEndsWith('.enc', $all[0]->filename);
    }

    public function test_restore_requires_the_typed_confirmation(): void
    {
        $info = $this->manager->create();

        Livewire::actingAs($this->owner)
            ->test('backup.index')
            ->call('openRestore', $info->filename)
            ->assertSet('restoreTarget', $info->filename)
            ->set('confirmText', 'salah')
            ->call('restoreBackup');

        // Tanpa konfirmasi benar: tidak ada snapshot pengaman & tidak ada audit.
        $this->assertSame([], glob($this->backupDir.'/pre-restore/*', GLOB_NOSORT) ?: []);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::BackupRestored->value)->count());
    }

    public function test_owner_can_restore_from_the_ui_with_audit_trail(): void
    {
        $info = $this->manager->create();

        // Ubah database uji setelah backup — perubahan harus hilang setelah restore.
        $this->pdo($this->dbFile)->exec("INSERT INTO items (name, stock) VALUES ('Durian', 1)");
        $this->assertSame(['Apel', 'Mangga', 'Durian'], $this->rows($this->dbFile));

        Livewire::actingAs($this->owner)
            ->test('backup.index')
            ->call('openRestore', $info->filename)
            ->set('confirmText', 'RESTORE')
            ->call('restoreBackup');

        // Isi database kembali seperti saat backup.
        $this->assertSame(['Apel', 'Mangga'], $this->rows($this->dbFile));

        // Snapshot pengaman dibuat otomatis (FR-11 §21).
        $this->assertCount(2, $this->manager->all());

        // Jejak audit mencatat aktor + jenis tindakan (FR-12).
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::BackupRestored->value,
            'actor_id' => $this->owner->id,
        ]);
    }

    public function test_restore_of_a_tampered_backup_reports_an_error_without_restoring(): void
    {
        $info = $this->manager->create();
        file_put_contents($info->path, (string) file_get_contents($info->path).'x');

        $this->pdo($this->dbFile)->exec("INSERT INTO items (name, stock) VALUES ('Durian', 1)");

        Livewire::actingAs($this->owner)
            ->test('backup.index')
            ->call('openRestore', $info->filename)
            ->set('confirmText', 'RESTORE')
            ->call('restoreBackup');

        // Database tidak berubah dan modal tetap terbuka (pengguna bisa melihat galat).
        $this->assertSame(['Apel', 'Mangga', 'Durian'], $this->rows($this->dbFile));
        $this->assertSame([], glob($this->backupDir.'/pre-restore/*', GLOB_NOSORT) ?: []);
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

    /**
     * @return list<string>
     */
    private function rows(string $path): array
    {
        return $this->pdo($path)
            ->query('SELECT name FROM items ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
    }
}
