<?php

namespace Tests\Feature\Sync;

use App\Actions\Sync\PullServerChanges;
use App\Actions\Sync\SyncNow;
use App\Enums\SyncChangeOperation;
use App\Enums\SyncConflictStatus;
use App\Enums\SyncConflictType;
use App\Enums\SyncOperationType;
use App\Exceptions\Sync\SyncTransportException;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncChange;
use App\Models\SyncConflict;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Devices\DeviceToken;
use App\Support\Sync\DatabaseSyncChangeFeed;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Penarikan perubahan server ke perangkat (§11.3 langkah 9–10, §11.1, §11.5).
 *
 * Perangkat harus dapat menarik perubahan sejak kursor terakhir, menerapkannya
 * pada basis data lokal TANPA mengulang efek (§11.1), tanpa memantulkan
 * perubahannya sendiri kembali, dan tanpa menghapus pekerjaan lokal yang lebih
 * baru — konflik seperti itu dicatat sebagai kasus (§11.3 langkah 11).
 *
 * Skenario perangkat "belum punya" data disimulasikan dengan baris jurnal
 * `sync_changes` yang dibuat langsung (tanpa baris lokal), karena pada mode
 * satu basis data ini server dan perangkat berbagi tabel yang sama.
 */
class PullSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private string $token;

    private Category $category;

    /**
     * Payload produk "dari server": semua kolom wajib terisi karena berasal
     * dari baris nyata, tanpa meninggalkan jejak di basis data uji.
     *
     * @var array<string, mixed>
     */
    private array $templatePayload = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $token = DeviceToken::generate();
        $this->token = $token->plaintext;
        $this->device = Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'token_hash' => $token->hash,
            'token_prefix' => $token->prefix,
        ]);

        $this->category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $template = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
        ]);

        $this->templatePayload = $template->attributesToArray();

        $template->forceDelete();

        // Jurnal bersih: hanya perubahan yang disengaja yang diuji.
        SyncChange::query()->delete();
    }

    private function puller(?LoopbackSyncTransport $transport = null): PullServerChanges
    {
        return new PullServerChanges($this->tenant->id, $this->device->id, $transport ?? new LoopbackSyncTransport);
    }

    /**
     * Tulis satu baris jurnal server tanpa menyentuh model bisnis.
     *
     * @param  array<string, mixed>  $payload
     */
    private function journal(
        string $entityType,
        string $entityId,
        SyncChangeOperation $operation,
        array $payload = [],
        ?string $deviceId = null,
        ?CarbonImmutable $createdAt = null,
    ): SyncChange {
        $change = new SyncChange([
            'tenant_id' => $this->tenant->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation->value,
            'payload' => $payload,
            'device_id' => $deviceId,
        ]);

        if ($createdAt !== null) {
            $change->created_at = $createdAt;
        }

        $change->save();

        return $change;
    }

    /**
     * Payload jurnal nyata untuk produk: bentuknya sama persis dengan yang
     * ditulis `SyncChangeJournal` (seluruh kolom, termasuk yang wajib),
     * dengan nama/slug/sku unik per panggilan.
     *
     * @return array<string, mixed>
     */
    private function serverProductPayload(string $name = 'Kopi Ambon'): array
    {
        return array_merge($this->templatePayload, [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'barcode' => null,
            'stock' => 7,
            'price' => '15000.00',
            'buy_price' => '9000.00',
            'category_id' => $this->category->id,
        ]);
    }

    // ------------------------------------------------------- penerapan dasar

    public function test_a_product_created_on_the_server_is_created_locally(): void
    {
        $payload = $this->serverProductPayload('Kopi Ambon');
        $uuid = (string) Str::uuid7();

        $this->journal('product', $uuid, SyncChangeOperation::Created, $payload);

        $summary = $this->puller()->handle($this->token);

        $this->assertSame(1, $summary->applied);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(0, $summary->conflicted);
        $this->assertSame(1, $summary->pages);

        $product = Product::query()->where('uuid', $uuid)->sole();

        // Identitas sinkronisasi dari server (§17.1) dan tenant penerima (§12).
        $this->assertSame('Kopi Ambon', $product->name);
        $this->assertSame(7, $product->stock);
        $this->assertSame($this->tenant->id, $product->tenant_id);

        // Kursor tersimpan di perangkat sehingga putaran berikutnya melanjutkan.
        $this->assertNotNull($summary->cursor);
        $this->assertSame($summary->cursor, $this->device->refresh()->last_sync_cursor);

        // §11.1: menerapkan perubahan TIDAK mencatat perubahan baru — kalau
        // tidak, perubahan itu akan memantul ke perangkat lain tanpa henti.
        $this->assertSame(1, SyncChange::query()->count());
    }

    public function test_an_update_from_the_server_is_applied_when_it_is_newer(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Nama Lama',
        ]);

        SyncChange::query()->delete();

        $this->journal('product', $product->uuid, SyncChangeOperation::Updated, [
            'name' => 'Nama Baru',
            'price' => '17500.00',
            'stock' => 12,
        ]);

        $summary = $this->puller()->handle($this->token);

        $this->assertSame(1, $summary->applied);

        $product->refresh();
        $this->assertSame('Nama Baru', $product->name);
        $this->assertSame(12, $product->stock);
    }

    public function test_reapplying_the_same_change_has_no_effect(): void
    {
        $payload = $this->serverProductPayload();
        $uuid = (string) Str::uuid7();

        $change = $this->journal('product', $uuid, SyncChangeOperation::Created, $payload);

        $this->puller()->handle($this->token);
        $created = Product::query()->where('uuid', $uuid)->sole();
        $updatedAt = $created->updated_at;

        // Perangkat menarik ulang dari kursor nol (mis. setelah pemulihan):
        // perubahan yang sama tidak boleh menggandakan atau mengubah apa pun.
        $this->device->update(['last_sync_cursor' => null]);
        $summary = $this->puller()->handle($this->token);

        $this->assertSame(0, $summary->applied);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(1, Product::query()->where('uuid', $uuid)->count());
        $this->assertEquals($updatedAt, Product::query()->where('uuid', $uuid)->sole()->updated_at);
        $this->assertSame(1, SyncChange::query()->count());
        $this->assertSame($change->sequence, (int) $summary->cursor);
    }

    // ------------------------------------------------------------- pantulan

    public function test_changes_made_by_this_device_are_not_reapplied(): void
    {
        $payload = $this->serverProductPayload();
        $uuid = (string) Str::uuid7();

        // Perubahan berasal dari perangkat ini sendiri (§11.1).
        $this->journal('product', $uuid, SyncChangeOperation::Created, $payload, $this->device->id);

        $summary = $this->puller()->handle($this->token);

        $this->assertSame(0, $summary->applied);
        $this->assertSame(1, $summary->skipped);
        $this->assertNull(Product::query()->where('uuid', $uuid)->first());
    }

    public function test_entity_types_that_are_not_mirrored_are_skipped(): void
    {
        $uuid = (string) Str::uuid7();

        $this->journal('transaction', $uuid, SyncChangeOperation::Created, ['total_minor' => 1000]);

        $summary = $this->puller()->handle($this->token);

        $this->assertSame(0, $summary->applied);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(0, Transaction::query()->count());

        // Kursor tetap bergerak: perangkat tidak terjebak mengulang selamanya
        // jenis entitas yang memang bukan bagiannya (§11.5).
        $this->assertNotNull($summary->cursor);
    }

    // -------------------------------------------------------------- konflik

    public function test_a_stale_server_change_does_not_overwrite_a_newer_local_edit(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Suntingan Lokal',
        ]);

        SyncChange::query()->delete();

        // Perubahan server lebih tua daripada suntingan lokal yang belum
        // terkirim (§11.3 langkah 11).
        $this->journal(
            'product',
            $product->uuid,
            SyncChangeOperation::Updated,
            ['name' => 'Nilai Server'],
            null,
            CarbonImmutable::now()->subMinutes(5),
        );

        $summary = $this->puller()->handle($this->token);

        $this->assertSame(0, $summary->applied);
        $this->assertSame(1, $summary->conflicted);
        $this->assertSame('Suntingan Lokal', $product->refresh()->name);

        $conflict = SyncConflict::query()->sole();

        $this->assertSame(SyncConflictType::Data, $conflict->type);
        $this->assertSame(SyncConflictStatus::Open, $conflict->status);
        $this->assertSame('product', $conflict->entity_type);
        $this->assertSame($product->uuid, $conflict->entity_id);
        $this->assertSame($this->device->id, $conflict->device_id);
        $this->assertSame('local_change_is_newer', $conflict->details['reason']);
    }

    public function test_conflict_cases_are_not_duplicated_on_replay(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Suntingan Lokal',
        ]);

        SyncChange::query()->delete();

        $this->journal(
            'product',
            $product->uuid,
            SyncChangeOperation::Updated,
            ['name' => 'Nilai Server'],
            null,
            CarbonImmutable::now()->subMinutes(5),
        );

        $this->puller()->handle($this->token);
        $this->device->update(['last_sync_cursor' => null]);
        $this->puller()->handle($this->token);

        $this->assertSame(1, SyncConflict::query()->count());
    }

    // ------------------------------------------------------------- penghapusan

    public function test_a_deletion_from_the_server_removes_the_local_row(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
        ]);

        SyncChange::query()->delete();

        $this->journal('product', $product->uuid, SyncChangeOperation::Deleted);

        $summary = $this->puller()->handle($this->token);

        $this->assertSame(1, $summary->applied);
        $this->assertNull(Product::query()->where('uuid', $product->uuid)->first());
    }

    public function test_a_deletion_for_an_unknown_row_is_skipped(): void
    {
        $this->journal('product', (string) Str::uuid7(), SyncChangeOperation::Deleted);

        $summary = $this->puller()->handle($this->token);

        $this->assertSame(0, $summary->applied);
        $this->assertSame(1, $summary->skipped);
    }

    // -------------------------------------------------------------- halaman

    public function test_pages_are_pulled_until_the_server_has_no_more(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $this->journal('product', (string) Str::uuid7(), SyncChangeOperation::Created, $this->serverProductPayload($name));
        }

        $summary = $this->puller()->handle($this->token, limit: 2);

        $this->assertSame(5, $summary->applied);
        $this->assertSame(3, $summary->pages);
        $this->assertFalse($summary->hasMore);
        $this->assertSame(5, Product::query()->count());
        $this->assertSame((string) SyncChange::query()->max('sequence'), $summary->cursor);
    }

    public function test_the_page_limit_bounds_one_round_and_keeps_progress(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $this->journal('product', (string) Str::uuid7(), SyncChangeOperation::Created, $this->serverProductPayload($name));
        }

        $summary = $this->puller()->handle($this->token, limit: 2, maxPages: 1);

        $this->assertSame(2, $summary->applied);
        $this->assertSame(1, $summary->pages);
        $this->assertTrue($summary->hasMore);
        $this->assertSame($summary->cursor, $this->device->refresh()->last_sync_cursor);

        // Putaran berikutnya menyusul sisanya (§11.5).
        $summary = $this->puller()->handle($this->token, limit: 2);

        $this->assertSame(3, $summary->applied);
        $this->assertFalse($summary->hasMore);
        $this->assertSame(5, Product::query()->count());
    }

    // ------------------------------------------------------- kegagalan & token

    public function test_a_transport_failure_leaves_the_cursor_untouched(): void
    {
        $this->device->update(['last_sync_cursor' => '5']);

        $this->journal('product', (string) Str::uuid7(), SyncChangeOperation::Created, $this->serverProductPayload());

        try {
            $this->puller((new LoopbackSyncTransport)->goOffline())->handle($this->token);
            $this->fail('Penarikan seharusnya melempar SyncTransportException.');
        } catch (SyncTransportException) {
            // Hasil tidak diketahui: perangkat harus mengulang dari kursor lama.
        }

        $this->assertSame('5', $this->device->refresh()->last_sync_cursor);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_pull_requires_a_configured_device_token(): void
    {
        config()->set('pos.sync.device_token', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Token sinkronisasi/');

        $this->puller()->handle();
    }

    public function test_a_revoked_device_cannot_pull(): void
    {
        $this->device->update(['revoked_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dicabut/');

        $this->puller()->handle($this->token);
    }

    // ------------------------------------------------- putaran penuh §11.3

    public function test_sync_now_pushes_and_then_pulls_in_one_round(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        (new Outbox($this->tenant->id))->enqueue(
            $this->device->id,
            'sale',
            (string) Str::uuid7(),
            SyncOperationType::Create,
            ['user_id' => $user->id, 'paid_minor' => 1000, 'items' => []],
            (string) Str::uuid7(),
        );

        $transport = (new FakeSyncTransport)->setStatus(SyncOutbox::query()->sole()->operation_id, 'received');

        $payload = $this->serverProductPayload('Produk dari Server');
        $uuid = (string) Str::uuid7();
        $this->journal('product', $uuid, SyncChangeOperation::Created, $payload);

        // Halaman tarik dibangun dari jurnal nyata (bentuk yang sama dengan
        // yang dikirim endpoint `/api/v1/sync/pull`).
        $transport->setPullPages([(new DatabaseSyncChangeFeed)->since(null, 100)]);

        config()->set('pos.sync.device_token', $this->token);

        $summary = (new SyncNow($this->tenant->id, $this->device->id, $transport))
            ->handle($this->token);

        $this->assertSame(1, $summary->sent);
        $this->assertSame(1, $summary->synced);
        $this->assertNotNull($summary->pulled);
        $this->assertSame(1, $summary->pulled->applied);
        $this->assertNull($summary->pullError);
        $this->assertNotNull(Product::query()->where('uuid', $uuid)->first());
    }

    public function test_a_pull_failure_does_not_undo_the_push_and_is_reported(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        (new Outbox($this->tenant->id))->enqueue(
            $this->device->id,
            'sale',
            (string) Str::uuid7(),
            SyncOperationType::Create,
            ['user_id' => $user->id, 'paid_minor' => 1000, 'items' => []],
            (string) Str::uuid7(),
        );

        $transport = (new FakeSyncTransport)
            ->setStatus(SyncOutbox::query()->sole()->operation_id, 'received')
            ->failNextPull(new SyncTransportException('Koneksi ke server gagal.'));

        config()->set('pos.sync.device_token', $this->token);

        $summary = (new SyncNow($this->tenant->id, $this->device->id, $transport))
            ->handle($this->token);

        $this->assertSame(1, $summary->synced);
        $this->assertNull($summary->pulled);
        $this->assertSame('Koneksi ke server gagal.', $summary->pullError);
        $this->assertSame('synced', SyncOutbox::query()->sole()->sync_status->value);
    }
}
