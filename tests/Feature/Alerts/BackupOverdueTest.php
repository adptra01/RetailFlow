<?php

namespace Tests\Feature\Alerts;

use App\Backup\BackupInfo;
use App\Backup\BackupManager;
use App\Backup\Drivers\SqliteBackupDriver;
use App\Models\Alert;
use App\Models\Tenant;
use App\Support\Alerts\AlertGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BackupOverdueTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private string $backupDir;

    private string $dbFile;

    private BackupManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_dir(storage_path('app/testing'))) {
            mkdir(storage_path('app/testing'), 0775, true);
        }

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->backupDir = storage_path('app/testing/alert-backups-'.uniqid());
        $this->dbFile = storage_path('app/testing/alert-db-'.uniqid().'.sqlite');
        $pdo = new \PDO('sqlite:'.$this->dbFile);
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO items (name) VALUES ('Apel')");

        $this->manager = new BackupManager(
            new SqliteBackupDriver($this->dbFile),
            $this->backupDir,
            '0.4.0',
        );
    }

    protected function tearDown(): void
    {
        foreach (array_merge(
            glob($this->backupDir.'/**/*', GLOB_NOSORT) ?: [],
            glob($this->backupDir.'/*', GLOB_NOSORT) ?: [],
        ) as $file) {
            is_file($file) ? @unlink($file) : @rmdir($file);
        }
        @unlink($this->dbFile);
        @unlink($this->dbFile.'-wal');
        @unlink($this->dbFile.'-shm');
        @rmdir($this->backupDir);

        parent::tearDown();
    }

    public function test_alert_type_constant(): void
    {
        $this->assertSame('backup.overdue', Alert::TYPE_BACKUP_OVERDUE);
    }

    public function test_alert_created_when_backup_is_stale(): void
    {
        $info = $this->makeStaleBackup();

        $generator = new AlertGenerator($this->tenant->id, $this->manager);

        $this->assertSame(1, $generator->backupOverdue());

        $alert = Alert::query()->where('alert_type', Alert::TYPE_BACKUP_OVERDUE)->first();
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->severity);
        $this->assertNull($alert->read_at);
        $this->assertSame($info->createdAt, $alert->payload['last_backup_at']);
    }

    public function test_deduplicated_until_read_then_respawns(): void
    {
        $this->makeStaleBackup();

        $generator = new AlertGenerator($this->tenant->id, $this->manager);

        $this->assertSame(1, $generator->backupOverdue());
        $this->assertSame(0, $generator->backupOverdue());
        $this->assertSame(1, Alert::query()->where('alert_type', Alert::TYPE_BACKUP_OVERDUE)->count());

        // FR-10: setelah dibaca, kondisi yang bertahan boleh dibunyikan lagi.
        $alert = Alert::query()->where('alert_type', Alert::TYPE_BACKUP_OVERDUE)->first();
        $this->assertNotNull($alert);
        $alert->markAsRead();

        $this->assertSame(1, $generator->backupOverdue());
    }

    public function test_no_alert_when_backup_is_fresh(): void
    {
        $this->manager->create();

        $generator = new AlertGenerator($this->tenant->id, $this->manager);

        $this->assertSame(0, $generator->backupOverdue());
    }

    public function test_no_alert_when_never_backed_up(): void
    {
        $never = new BackupManager(
            new SqliteBackupDriver($this->dbFile),
            $this->backupDir.'/never',
            '0.4.0',
        );

        $this->assertSame(0, (new AlertGenerator($this->tenant->id, $never))->backupOverdue());
    }

    public function test_wired_into_run(): void
    {
        $this->makeStaleBackup();

        $this->assertSame(1, (new AlertGenerator($this->tenant->id, $this->manager))->run());
    }

    private function makeStaleBackup(): BackupInfo
    {
        $info = $this->manager->create();
        $old = now()->subDays(10)->toIso8601String();

        $sidecar = $this->backupDir.'/'.$info->filename.'.meta.json';
        $metadata = json_decode((string) file_get_contents($sidecar), true);
        $metadata['created_at'] = $old;
        file_put_contents($sidecar, json_encode($metadata));

        $manifestPath = $this->manager->manifestPath();
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifest['backups'][0]['created_at'] = $old;
        $manifest['last_backup_at'] = $old;
        file_put_contents($manifestPath, json_encode($manifest));

        return BackupInfo::fromArray($manifest['backups'][0]);
    }
}
