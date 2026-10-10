<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncChangeOperation;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncChange;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Devices\DeviceContext;
use App\Support\Devices\DeviceToken;
use App\Support\Sync\DatabaseSyncChangeFeed;
use App\Support\Sync\SyncChangeJournal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 4 item 4 — Cursor sinkronisasi (§11.3 langkah 9, §12).
 */
class CursorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        // Kategori dasar agar ProductFactory tidak membuat kategori baru
        // (yang akan ikut menulis jurnal), lalu mulai dari jurnal kosong.
        $this->category = Category::factory()->create();
        SyncChange::query()->delete();
    }

    private function feed(): DatabaseSyncChangeFeed
    {
        return new DatabaseSyncChangeFeed;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge(['category_id' => $this->category->id], $attributes));
    }

    // -------------------------------------------------------------- jurnal

    public function test_creating_a_record_journals_a_change(): void
    {
        $product = $this->makeProduct(['name' => 'Kopi']);

        $change = SyncChange::query()->sole();

        $this->assertSame($this->tenant->id, $change->tenant_id);
        $this->assertSame('product', $change->entity_type);
        $this->assertSame($product->uuid, $change->entity_id);
        $this->assertSame(SyncChangeOperation::Created, $change->operation);
        $this->assertSame('Kopi', $change->payload['name']);
        $this->assertNull($change->device_id);
    }

    public function test_update_and_delete_are_journaled(): void
    {
        $product = $this->makeProduct();

        $product->update(['name' => 'Kopi Susu']);
        $product->delete();

        $operations = SyncChange::query()->orderBy('sequence')->pluck('operation')->all();

        $this->assertSame(
            [SyncChangeOperation::Created, SyncChangeOperation::Updated, SyncChangeOperation::Deleted],
            $operations,
        );
    }

    public function test_records_without_tenant_are_not_journaled(): void
    {
        app(TenantContext::class)->set(null);

        Product::factory()->create();

        $this->assertSame(0, SyncChange::query()->count());
    }

    public function test_journal_write_is_atomic_with_the_domain_change(): void
    {
        DB::beginTransaction();
        Product::factory()->create();
        DB::rollBack();

        $this->assertSame(0, SyncChange::query()->count());
    }

    public function test_journal_records_origin_device(): void
    {
        $device = Device::factory()->create(['tenant_id' => $this->tenant->id]);
        app(DeviceContext::class)->set($device);

        $this->makeProduct();

        $this->assertSame($device->id, SyncChange::query()->sole()->device_id);
    }

    public function test_payload_excludes_hidden_attributes(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $change = (new SyncChangeJournal)->record($user, SyncChangeOperation::Created);

        $this->assertNotNull($change);
        $this->assertArrayNotHasKey('password', $change->payload);
        $this->assertArrayNotHasKey('remember_token', $change->payload);
    }

    // ---------------------------------------------------------------- feed

    public function test_feed_returns_changes_ordered_by_sequence(): void
    {
        $this->makeProduct(['name' => 'A']);
        $this->makeProduct(['name' => 'B']);

        $batch = $this->feed()->since(null, 100);

        $this->assertCount(2, $batch->changes);
        $this->assertSame('A', $batch->changes[0]['payload']['name']);
        $this->assertSame('B', $batch->changes[1]['payload']['name']);
        $this->assertSame('created', $batch->changes[0]['operation']);
        $this->assertFalse($batch->hasMore);
        $this->assertSame((string) SyncChange::query()->max('sequence'), $batch->cursor);
    }

    public function test_feed_only_returns_changes_after_the_cursor(): void
    {
        $this->makeProduct(['name' => 'A']);
        $first = $this->feed()->since(null, 100);

        $this->makeProduct(['name' => 'B']);
        $second = $this->feed()->since($first->cursor, 100);

        $this->assertCount(1, $second->changes);
        $this->assertSame('B', $second->changes[0]['payload']['name']);
    }

    public function test_feed_paginates_without_missing_or_duplicating(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $this->makeProduct(['name' => $name]);
        }

        $cursor = null;
        $names = [];
        $pages = 0;

        do {
            $batch = $this->feed()->since($cursor, 2);
            $pages++;
            foreach ($batch->changes as $change) {
                $names[] = $change['payload']['name'];
            }
            $cursor = $batch->cursor;
        } while ($batch->hasMore);

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $names);
        $this->assertSame(3, $pages);
    }

    public function test_feed_is_tenant_isolated(): void
    {
        $other = Tenant::factory()->create();

        $this->makeProduct(['name' => 'Milik A']);

        app(TenantContext::class)->set($other);
        $this->makeProduct(['name' => 'Milik B']);

        app(TenantContext::class)->set($this->tenant);
        $batch = $this->feed()->since(null, 100);

        $this->assertCount(1, $batch->changes);
        $this->assertSame('Milik A', $batch->changes[0]['payload']['name']);
    }

    public function test_feed_requires_a_tenant(): void
    {
        app(TenantContext::class)->set(null);

        $this->expectException(\RuntimeException::class);

        $this->feed()->since(null, 10);
    }

    // ------------------------------------------------------------ endpoint

    public function test_pull_returns_real_changes_and_advances_cursor(): void
    {
        $token = DeviceToken::generate();
        $device = Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'token_hash' => $token->hash,
            'token_prefix' => $token->prefix,
        ]);

        $this->makeProduct(['name' => 'Kopi']);

        $response = $this->getJson('/api/v1/sync/pull', ['Authorization' => 'Bearer '.$token->plaintext]);

        $response->assertOk()
            ->assertJsonPath('changes.0.entity_type', 'product')
            ->assertJsonPath('changes.0.payload.name', 'Kopi')
            ->assertJsonPath('has_more', false);

        $cursor = $response->json('cursor');
        $this->assertSame((string) SyncChange::query()->max('sequence'), $cursor);

        $device->refresh();
        $this->assertSame($cursor, $device->last_sync_cursor);

        // Pull ulang dengan kursor yang sama tidak mengulang perubahan.
        $this->getJson('/api/v1/sync/pull?cursor='.$cursor, ['Authorization' => 'Bearer '.$token->plaintext])
            ->assertOk()
            ->assertJsonPath('changes', [])
            ->assertJsonPath('cursor', $cursor);
    }

    public function test_pull_paginates_with_limit(): void
    {
        $token = DeviceToken::generate();
        Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'token_hash' => $token->hash,
            'token_prefix' => $token->prefix,
        ]);

        foreach (['A', 'B', 'C'] as $name) {
            $this->makeProduct(['name' => $name]);
        }

        $this->getJson('/api/v1/sync/pull?limit=2', ['Authorization' => 'Bearer '.$token->plaintext])
            ->assertOk()
            ->assertJsonCount(2, 'changes')
            ->assertJsonPath('has_more', true);
    }
}
