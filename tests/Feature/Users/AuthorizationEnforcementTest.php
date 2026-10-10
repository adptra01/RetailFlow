<?php

namespace Tests\Feature\Users;

use App\Backup\BackupManager;
use App\Backup\Drivers\SqliteBackupDriver;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Backup\BackupRequiresConfirmation;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\SalesReport;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PDO;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bukti otorisasi FR-12 terpasang di titik sensitif yang sudah ada:
 *  - Gate `can()` terhubung ke katalog permission (AppServiceProvider);
 *  - laporan keuangan (`SalesReport`) — izin `report.financial.view`;
 *  - restore backup (`BackupManager::restore`) — izin `backup.restore`.
 */
class AuthorizationEnforcementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    private User $noRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $owners = [$this->owner = User::factory()->create(['tenant_id' => $this->tenant->id])];
        $owners[0]->assignRole(Role::Owner->value);

        $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->viewer->assignRole(Role::Viewer->value);

        $this->noRole = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_can_maps_catalog_permissions_via_gate(): void
    {
        $this->assertTrue($this->owner->can(Permission::ManageUsers->value));
        $this->assertTrue($this->owner->can(Permission::RestoreBackup->value));

        $this->assertTrue($this->viewer->can(Permission::ViewFinancialReports->value));
        $this->assertFalse($this->viewer->can(Permission::ApplyDiscount->value));

        $this->assertFalse($this->noRole->can(Permission::AccessModule->value));
    }

    public function test_sales_report_requires_financial_report_permission(): void
    {
        // Tanpa aktor (CLI) tetap berjalan — kompatibel ke belakang.
        $this->assertIsArray((new SalesReport($this->tenant->id))->daily());

        // Aktor viewer (punya izin) boleh.
        $this->assertIsArray((new SalesReport($this->tenant->id, $this->viewer))->daily());

        // Aktor tanpa izin ditolak.
        $this->expectException(ActionForbidden::class);

        (new SalesReport($this->tenant->id, $this->noRole))->daily();
    }

    public function test_backup_restore_requires_restore_permission(): void
    {
        $dbFile = storage_path('app/testing/restore-db-'.uniqid().'.sqlite');
        if (! is_dir(storage_path('app/testing'))) {
            mkdir(storage_path('app/testing'), 0775, true);
        }

        $pdo = new PDO('sqlite:'.$dbFile);
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO items (name) VALUES ('Apel')");

        $backupDir = storage_path('app/testing/restore-backups-'.uniqid());
        $manager = new BackupManager(new SqliteBackupDriver($dbFile), $backupDir, '0.4.0');
        $info = $manager->create();

        try {
            // Aktor tanpa izin `backup.restore` ditolak SEBELUM validasi file.
            try {
                $manager->restore($info->filename, force: true, actor: $this->noRole);
                $this->fail('Seharusnya dilempar ActionForbidden.');
            } catch (ActionForbidden $e) {
                $this->assertStringContainsString('Melakukan restore', $e->getMessage());
            }

            // Aktor owner lolos otorisasi → lanjut ke aturan konfirmasi FR-11.
            $this->expectException(BackupRequiresConfirmation::class);
            $manager->restore($info->filename, actor: $this->owner);
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
                if (is_file($file)) {
                    @unlink($file);
                }
                if (is_dir($file)) {
                    @rmdir($file);
                }
            }
            @rmdir($backupDir);
        }
    }
}
