<?php

namespace Tests\Feature\Sync;

use App\Actions\Sync\ResolveSyncConflict;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\SyncConflictStatus;
use App\Enums\SyncConflictType;
use App\Enums\SyncInboxStatus;
use App\Exceptions\Auth\ActionForbidden;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncChange;
use App\Models\SyncConflict;
use App\Models\SyncInbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Reports\StockSyncReport;
use App\Support\Devices\DeviceToken;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 4 item 7 — Konflik stok (§11.4, §11.5).
 */
class StockConflictTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private string $token;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $token = DeviceToken::generate();
        $this->token = $token->plaintext;
        $this->device = Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'token_hash' => $token->hash,
            'token_prefix' => $token->prefix,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create([
            'category_id' => $category->id,
            'tenant_id' => $this->tenant->id,
            'stock' => 1,
            'is_unlimited_stock' => false,
        ]);
    }

    private function saleOperation(int $quantity = 5, ?string $operationId = null): array
    {
        return [
            'operation_id' => $operationId ?? (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => (string) Str::uuid(),
            'operation_type' => 'create',
            'payload' => [
                'user_id' => $this->user->id,
                'paid_minor' => $quantity * 5000,
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity' => $quantity,
                    'unit_price_minor' => 5000,
                    'conversion_factor' => 1,
                ]],
            ],
            'local_sequence' => 1,
        ];
    }

    private function push(array $operations)
    {
        return $this->postJson(
            '/api/v1/sync/push',
            ['operations' => $operations],
            ['Authorization' => 'Bearer '.$this->token],
        );
    }

    private function actorWith(Permission $permission): User
    {
        $actor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
        $actor->givePermissionTo($permission->value);

        return $actor;
    }

    // ---------------------------------------------------------------- deteksi

    public function test_insufficient_stock_creates_an_open_conflict_case(): void
    {
        $op = $this->saleOperation(quantity: 5);

        $this->push([$op])->assertOk()->assertJsonPath('results.0.status', 'conflict');

        // Penjualan tidak diterapkan, stok tidak berubah.
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1, $this->product->refresh()->stock);

        $inbox = SyncInbox::query()->sole();
        $this->assertSame(SyncInboxStatus::Conflict, $inbox->status);

        $conflict = SyncConflict::query()->sole();
        $this->assertSame(SyncConflictType::Stock, $conflict->type);
        $this->assertSame(SyncConflictStatus::Open, $conflict->status);
        $this->assertSame($op['operation_id'], $conflict->operation_id);
        $this->assertSame('insufficient_stock', $conflict->details['reason']);
        $this->assertSame($this->product->id, $conflict->details['product_id']);
        $this->assertSame(1, $conflict->details['available']);
        $this->assertSame(5, $conflict->details['requested']);
        $this->assertSame(4, $conflict->details['shortfall']);
    }

    public function test_repeated_push_does_not_duplicate_the_conflict_case(): void
    {
        $op = $this->saleOperation(operationId: (string) Str::uuid());

        $this->push([$op])->assertOk();
        $this->push([$op])->assertOk()->assertJsonPath('results.0.duplicate', true);

        $this->assertSame(1, SyncConflict::query()->count());
    }

    public function test_conflict_case_is_journaled_for_device_sync(): void
    {
        $this->push([$this->saleOperation()])->assertOk();

        $this->assertTrue(
            SyncChange::query()->where('entity_type', 'sync_conflict')->exists(),
            'Kasus konflik harus masuk jurnal perubahan agar dapat ditarik perangkat.',
        );
    }

    // ------------------------------------------------------------ kebijakan

    public function test_allow_negative_stock_policy_applies_the_sale_and_records_the_conflict(): void
    {
        Config::set('pos.inventory.allow_negative_stock', true);

        $op = $this->saleOperation(quantity: 5);

        $this->push([$op])->assertOk()->assertJsonPath('results.0.status', 'applied');

        $transaction = Transaction::query()->sole();
        $this->assertSame(-4, $this->product->refresh()->stock);
        $this->assertSame($op['operation_id'], $transaction->client_request_id);

        $conflict = SyncConflict::query()->sole();
        $this->assertSame(SyncConflictStatus::Open, $conflict->status);
        $this->assertSame('negative_stock_applied', $conflict->details['reason']);
        $this->assertSame($transaction->uuid, $conflict->entity_id);
        $this->assertSame(-4, $conflict->details['products'][0]['after_stock']);
    }

    // ------------------------------------------------------------ penyelesaian

    public function test_allow_resolution_applies_the_held_sale_with_negative_stock(): void
    {
        $op = $this->saleOperation(quantity: 5);
        $this->push([$op])->assertOk();

        $conflict = SyncConflict::query()->sole();
        $actor = $this->actorWith(Permission::AdjustStock);

        (new ResolveSyncConflict($this->tenant->id, $actor))->allow($conflict, 'Disetujui owner.');

        $transaction = Transaction::query()->sole();
        $this->assertSame($op['operation_id'], $transaction->client_request_id);
        $this->assertSame(-4, $this->product->refresh()->stock);

        $conflict->refresh();
        $this->assertSame(SyncConflictStatus::Resolved, $conflict->status);
        $this->assertSame($actor->id, $conflict->resolved_by);
        $this->assertNotNull($conflict->resolved_at);
        $this->assertSame('allow_negative_stock', $conflict->resolution['action']);
        $this->assertSame($transaction->uuid, $conflict->resolution['transaction_uuid']);

        $inbox = SyncInbox::query()->sole();
        $this->assertSame(SyncInboxStatus::Applied, $inbox->status);
        $this->assertSame($transaction->uuid, $inbox->applied_entity_id);
    }

    public function test_resolution_requires_stock_permission(): void
    {
        $this->push([$this->saleOperation()])->assertOk();
        $conflict = SyncConflict::query()->sole();

        $plain = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);

        $this->expectException(ActionForbidden::class);

        (new ResolveSyncConflict($this->tenant->id, $plain))->allow($conflict);
    }

    public function test_dismiss_closes_the_case_without_applying(): void
    {
        $this->push([$this->saleOperation()])->assertOk();
        $conflict = SyncConflict::query()->sole();
        $actor = $this->actorWith(Permission::AdjustStock);

        (new ResolveSyncConflict($this->tenant->id, $actor))->dismiss($conflict, 'Transaksi batal di perangkat.');

        $conflict->refresh();
        $this->assertSame(SyncConflictStatus::Dismissed, $conflict->status);
        $this->assertSame('dismiss', $conflict->resolution['action']);

        // Data tidak dihapus: baris inbox tetap ada (§11.5).
        $this->assertSame(SyncInboxStatus::Conflict, SyncInbox::query()->sole()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1, $this->product->refresh()->stock);
    }

    public function test_owner_role_can_resolve_stock_conflicts(): void
    {
        $this->push([$this->saleOperation()])->assertOk();
        $conflict = SyncConflict::query()->sole();

        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        $owner->assignRole(Role::Owner->value);

        (new ResolveSyncConflict($this->tenant->id, $owner))->allow($conflict);

        $this->assertSame(SyncConflictStatus::Resolved, $conflict->refresh()->status);
    }

    // ---------------------------------------------------------------- laporan

    public function test_report_distinguishes_synced_and_estimated_stock(): void
    {
        $this->push([$this->saleOperation(quantity: 5)])->assertOk();

        $report = (new StockSyncReport($this->tenant->id))->summary();

        $row = collect($report['products'])->firstWhere('product_id', $this->product->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['stock_tersinkron']);
        $this->assertSame(5, $row['mutasi_tertunda']);
        $this->assertSame(-4, $row['stock_diperkirakan']);
        $this->assertSame(1, $row['konflik_terbuka']);

        $this->assertSame(1, $report['kasus_konflik_terbuka']);
        $this->assertSame(5, $report['operasi_tertunda']);
    }

    public function test_report_lists_open_conflicts(): void
    {
        $op = $this->saleOperation();
        $this->push([$op])->assertOk();

        $conflicts = (new StockSyncReport($this->tenant->id))->openConflicts();

        $this->assertCount(1, $conflicts);
        $this->assertSame($op['operation_id'], $conflicts[0]['operation_id']);
        $this->assertSame('insufficient_stock', $conflicts[0]['details']['reason']);
    }

    public function test_report_requires_permission_when_actor_is_given(): void
    {
        $plain = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);

        $this->expectException(ActionForbidden::class);

        (new StockSyncReport($this->tenant->id, $plain))->summary();
    }

    public function test_resolved_conflict_no_longer_counts_as_pending(): void
    {
        $this->push([$this->saleOperation(quantity: 5)])->assertOk();
        $actor = $this->actorWith(Permission::AdjustStock);

        (new ResolveSyncConflict($this->tenant->id, $actor))
            ->allow(SyncConflict::query()->sole());

        $report = (new StockSyncReport($this->tenant->id))->summary();
        $row = collect($report['products'])->firstWhere('product_id', $this->product->id);

        // Penjualan kini diterapkan: stok tersinkron = -4, tidak ada mutasi tertunda.
        $this->assertSame(-4, $row['stock_tersinkron']);
        $this->assertSame(0, $row['mutasi_tertunda']);
        $this->assertSame(0, $report['kasus_konflik_terbuka']);
    }
}
