<?php

namespace Tests\Feature\Sync;

use App\Actions\Sync\ResolveSyncConflict;
use App\Enums\Permission;
use App\Enums\SyncConflictStatus;
use App\Enums\SyncInboxStatus;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
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
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 4 item 10 — Uji dua perangkat (§11.3, §11.4, §11.5, §12).
 *
 * Dua perangkat offline menjual unit terakhir. Kedua penjualan harus tetap
 * tersimpan di perangkat masing-masing; server TIDAK boleh diam-diam menghapus
 * salah satunya. Perangkat pertama diterapkan, perangkat kedua menjadi kasus
 * konflik stok yang perlu diselesaikan.
 */
class TwoDeviceConflictTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Device $deviceA;

    private Device $deviceB;

    private string $tokenA;

    private string $tokenB;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        [$this->deviceA, $this->tokenA] = $this->makeDevice('Kasir A');
        [$this->deviceB, $this->tokenB] = $this->makeDevice('Kasir B');

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create([
            'category_id' => $category->id,
            'tenant_id' => $this->tenant->id,
            'stock' => 1,
            'is_unlimited_stock' => false,
        ]);
    }

    /**
     * @return array{0: Device, 1: string}
     */
    private function makeDevice(string $name): array
    {
        $token = DeviceToken::generate();

        $device = Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_name' => $name,
            'token_hash' => $token->hash,
            'token_prefix' => $token->prefix,
        ]);

        return [$device, $token->plaintext];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleOperation(int $quantity = 1): array
    {
        return [
            'operation_id' => (string) Str::uuid(),
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

    private function push(string $token, array $operations)
    {
        return $this->postJson(
            '/api/v1/sync/push',
            ['operations' => $operations],
            ['Authorization' => 'Bearer '.$token],
        );
    }

    // -------------------------------------------------------------- konflik dua perangkat

    public function test_first_device_wins_and_second_becomes_a_stock_conflict(): void
    {
        $opA = $this->saleOperation();
        $opB = $this->saleOperation();

        // Kedua perangkat menjual unit terakhir secara offline.
        $this->push($this->tokenA, [$opA])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied');

        $this->push($this->tokenB, [$opB])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'conflict');

        // Server tidak menghapus salah satu diam-diam: A diterapkan, B tercatat.
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame($opA['operation_id'], Transaction::query()->sole()->client_request_id);
        $this->assertSame(0, $this->product->refresh()->stock);

        // Penjualan B tertahan sebagai kasus konflik, bukan dibuang.
        $conflict = SyncConflict::query()->sole();
        $this->assertSame($opB['operation_id'], $conflict->operation_id);
        $this->assertSame($this->deviceB->id, $conflict->device_id);
        $this->assertSame('insufficient_stock', $conflict->details['reason']);

        $inboxB = SyncInbox::query()->where('operation_id', $opB['operation_id'])->sole();
        $this->assertSame(SyncInboxStatus::Conflict, $inboxB->status);

        $inboxA = SyncInbox::query()->where('operation_id', $opA['operation_id'])->sole();
        $this->assertSame(SyncInboxStatus::Applied, $inboxA->status);
    }

    public function test_each_device_status_is_isolated(): void
    {
        $opA = $this->saleOperation();
        $opB = $this->saleOperation();

        $this->push($this->tokenA, [$opA])->assertOk();
        $this->push($this->tokenB, [$opB])->assertOk();

        // Baris inbox mencatat perangkat asalnya masing-masing (§12).
        $this->assertSame(
            $this->deviceA->id,
            SyncInbox::query()->where('operation_id', $opA['operation_id'])->sole()->device_id,
        );
        $this->assertSame(
            $this->deviceB->id,
            SyncInbox::query()->where('operation_id', $opB['operation_id'])->sole()->device_id,
        );
    }

    public function test_repeated_conflict_push_stays_conflicted_until_resolved(): void
    {
        $opA = $this->saleOperation();
        $opB = $this->saleOperation();

        $this->push($this->tokenA, [$opA])->assertOk();

        // B gagal: stok habis.
        $this->push($this->tokenB, [$opB])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'conflict');

        // Stok diisi ulang. Pengiriman ulang TIDAK diam-diam menerapkan operasi
        // yang sudah tercatat konflik — hasilnya tetap `conflict` sampai
        // pengguna berwenang menyelesaikannya (§11.4, §11.5).
        $this->product->update(['stock' => 5]);

        $this->push($this->tokenB, [$opB])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.duplicate', true);

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(5, $this->product->refresh()->stock);

        // Setelah owner menyelesaikan, pengiriman ulang melaporkan `applied`.
        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        $owner->givePermissionTo(Permission::AdjustStock->value);

        (new ResolveSyncConflict($this->tenant->id, $owner))
            ->allow(SyncConflict::query()->sole());

        $this->push($this->tokenB, [$opB])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.duplicate', true);

        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(4, $this->product->refresh()->stock);
    }

    public function test_owner_can_resolve_the_conflict_by_allowing_negative_stock(): void
    {
        $opA = $this->saleOperation();
        $opB = $this->saleOperation();

        $this->push($this->tokenA, [$opA])->assertOk();
        $this->push($this->tokenB, [$opB])->assertOk();

        $conflict = SyncConflict::query()->sole();

        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        $owner->givePermissionTo(Permission::AdjustStock->value);

        (new ResolveSyncConflict($this->tenant->id, $owner))->allow($conflict, 'Kedua penjualan sah.');

        // Kedua penjualan kini ada; stok negatif menandai ketidaksesuaian (§11.4).
        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(-1, $this->product->refresh()->stock);
        $this->assertSame(SyncConflictStatus::Resolved, $conflict->refresh()->status);
        $this->assertSame(
            SyncInboxStatus::Applied,
            SyncInbox::query()->where('operation_id', $opB['operation_id'])->sole()->status,
        );
    }

    public function test_report_shows_the_unsynced_estimate_until_the_conflict_is_resolved(): void
    {
        $this->push($this->tokenA, [$this->saleOperation()])->assertOk();
        $this->push($this->tokenB, [$this->saleOperation()])->assertOk();

        $report = (new StockSyncReport($this->tenant->id))->summary();
        $row = collect($report['products'])->firstWhere('product_id', $this->product->id);

        // Stok fisik diperkirakan berbeda dari stok tersinkron (§11.4).
        $this->assertSame(0, $row['stock_tersinkron']);
        $this->assertSame(1, $row['mutasi_tertunda']);
        $this->assertSame(-1, $row['stock_diperkirakan']);
        $this->assertSame(1, $report['kasus_konflik_terbuka']);
    }

    // ------------------------------------------------------------------ kursor terpisah

    public function test_devices_keep_independent_pull_cursors(): void
    {
        // Satu perangkat membuat penjualan yang masuk jurnal perubahan.
        $this->push($this->tokenA, [$this->saleOperation()])->assertOk();

        $responseA = $this->getJson('/api/v1/sync/pull', [
            'Authorization' => 'Bearer '.$this->tokenA,
        ])->assertOk();

        $cursorA = $responseA->json('cursor');
        $this->assertNotNull($cursorA);

        // Kursor A tersimpan hanya untuk perangkat A.
        $this->assertSame((string) $cursorA, (string) $this->deviceA->refresh()->last_sync_cursor);
        $this->assertNull($this->deviceB->refresh()->last_sync_cursor);

        // Perangkat B menarik dari nol: mendapat perubahan yang sama, kursor
        // awalnya sendiri tidak terpengaruh oleh A.
        $responseB = $this->getJson('/api/v1/sync/pull', [
            'Authorization' => 'Bearer '.$this->tokenB,
        ])->assertOk();

        $this->assertGreaterThan(0, count($responseB->json('changes')));
        $this->assertNotNull($this->deviceB->refresh()->last_sync_cursor);

        // A menarik lagi dari kursornya: tidak ada perubahan baru.
        $this->getJson('/api/v1/sync/pull?cursor='.$cursorA, [
            'Authorization' => 'Bearer '.$this->tokenA,
        ])->assertOk()->assertJsonPath('changes', []);
    }

    public function test_revoked_device_cannot_push_or_pull(): void
    {
        $this->deviceB->update(['revoked_at' => now()]);

        $this->push($this->tokenB, [$this->saleOperation()])->assertUnauthorized();
        $this->getJson('/api/v1/sync/pull', [
            'Authorization' => 'Bearer '.$this->tokenB,
        ])->assertUnauthorized();
    }
}
