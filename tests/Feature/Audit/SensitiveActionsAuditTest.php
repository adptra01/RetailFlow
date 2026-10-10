<?php

namespace Tests\Feature\Audit;

use App\Actions\Users\ManageUser;
use App\Backup\BackupManager;
use App\Backup\Drivers\SqliteBackupDriver;
use App\Enums\Role;
use App\Exceptions\Users\LastOwnerProtected;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\SalesReport;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PDO;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bukti FR-12 bahwa tindakan sensitif (pengelolaan pengguna, restore backup,
 * akses laporan keuangan) tercatat dengan aktor + jenis tindakan + alasan.
 */
class SensitiveActionsAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ManageUser $manage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->manage = new ManageUser($this->tenant->id);
    }

    /**
     * @return array<string, string>
     */
    private function input(string $email = 'audit-kasir@toko.test'): array
    {
        return [
            'name' => 'Kasir Audit',
            'email' => $email,
            'password' => 'rahasia-betul!123',
            'password_confirmation' => 'rahasia-betul!123',
        ];
    }

    public function test_create_user_records_audit_with_entity_and_role(): void
    {
        $created = $this->manage->create($this->owner, $this->input(), Role::Cashier);

        $log = AuditLog::query()->where('action', 'user.created')->firstOrFail();

        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame('user', $log->entity_type);
        $this->assertSame($created->uuid, $log->entity_id);
        $this->assertSame('cashier', $log->metadata['role']);
    }

    public function test_deactivation_records_reason(): void
    {
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->manage->setActive($this->owner, $target->id, false, 'Resign per '.(now()->toDateString()));

        $log = AuditLog::query()->where('action', 'user.deactivated')->firstOrFail();

        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame($target->uuid, $log->entity_id);
        $this->assertStringContainsString('Resign', $log->reason);
    }

    public function test_reactivation_records_audit(): void
    {
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->manage->setActive($this->owner, $target->id, false);
        $this->manage->setActive($this->owner, $target->id, true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.reactivated']);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.reactivated')->count());
    }

    public function test_role_change_records_metadata(): void
    {
        $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $target->assignRole('cashier');

        $this->manage->assignRole($this->owner, $target->id, Role::Supervisor, 'Naik jabatan');

        $log = AuditLog::query()->where('action', 'user.role_changed')->firstOrFail();

        $this->assertSame('supervisor', $log->metadata['role']);
        $this->assertSame('Naik jabatan', $log->reason);
    }

    public function test_failed_validation_writes_no_audit(): void
    {
        try {
            $this->manage->create($this->owner, ['name' => '', 'email' => 'x', 'password' => 'y', 'password_confirmation' => 'z']);
            $this->fail('Seharusnya ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_blocked_last_owner_demotion_writes_no_audit(): void
    {
        try {
            $this->manage->assignRole($this->owner, $this->owner->id, Role::Cashier);
            $this->fail('Seharusnya LastOwnerProtected.');
        } catch (LastOwnerProtected) {
            // expected
        }

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_backup_restore_records_audit_entry(): void
    {
        $dbFile = storage_path('app/testing/audit-restore-'.uniqid().'.sqlite');
        if (! is_dir(storage_path('app/testing'))) {
            mkdir(storage_path('app/testing'), 0775, true);
        }
        $backupDir = storage_path('app/testing/audit-backups-'.uniqid());

        try {
            $pdo = new PDO('sqlite:'.$dbFile);
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
            $pdo->exec("INSERT INTO items (name) VALUES ('Apel')");

            $manager = new BackupManager(new SqliteBackupDriver($dbFile), $backupDir, '0.4.0');
            $info = $manager->create();

            $manager->restore($info->filename, force: true, actor: $this->owner);

            $log = AuditLog::query()->where('action', 'backup.restored')->firstOrFail();

            $this->assertSame($this->owner->id, $log->actor_id);
            $this->assertSame($this->tenant->id, $log->tenant_id);
            $this->assertSame($info->filename, $log->metadata['backup']);
            $this->assertSame('0.4.0', $log->metadata['schema_version']);
            $this->assertNotNull($log->reason);
        } finally {
            foreach ([$dbFile, $dbFile.'-wal', $dbFile.'-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            foreach (glob($backupDir.'/**/*', GLOB_NOSORT) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            foreach (glob($backupDir.'/*', GLOB_NOSORT) ?: [] as $file) {
                is_dir($file) ? @rmdir($file) : @unlink($file);
            }
            @rmdir($backupDir);
        }
    }

    public function test_financial_report_access_is_audited_only_with_actor(): void
    {
        // Tanpa aktor (CLI/otomasi) → tidak ada jejak.
        (new SalesReport($this->tenant->id))->daily();
        $this->assertSame(0, AuditLog::query()->where('action', 'report.financial.viewed')->count());

        // Dengan aktor berizin → tercatat.
        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $viewer->assignRole('viewer');

        (new SalesReport($this->tenant->id, $viewer))->daily();

        $log = AuditLog::query()->where('action', 'report.financial.viewed')->firstOrFail();
        $this->assertSame($viewer->id, $log->actor_id);
        $this->assertArrayHasKey('from', $log->metadata);
    }
}
