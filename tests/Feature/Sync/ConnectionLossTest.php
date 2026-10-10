<?php

namespace Tests\Feature\Sync;

use App\Actions\Sales\CreateSale;
use App\Actions\Sync\PushOutbox;
use App\Enums\SyncInboxStatus;
use App\Enums\SyncStatus;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncInbox;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Reports\SyncStatusReport;
use App\Support\Devices\DeviceToken;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 4 item 9 — Uji koneksi putus (§11.1, §11.3, §11.5).
 *
 * Skenario: perangkat kehilangan koneksi, kasir tetap bisa menjual (penjualan
 * lokal + operasi outbox), pengiriman gagal berulang, lalu koneksi kembali dan
 * seluruh antrean tersinkron tanpa kehilangan atau menggandakan data.
 */
class ConnectionLossTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private User $user;

    private Product $product;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $generated = DeviceToken::generate();
        $this->token = $generated->plaintext;

        $this->device = Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_name' => 'Kasir Offline',
            'token_hash' => $generated->hash,
            'token_prefix' => $generated->prefix,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->product = Product::factory()->create([
            'category_id' => $category->id,
            'tenant_id' => $this->tenant->id,
            'stock' => 50,
            'is_unlimited_stock' => false,
        ]);
    }

    /**
     * Penjualan lokal di perangkat → transaksi + satu operasi outbox pending.
     */
    private function offlineSale(int $quantity = 1, ?string $clientRequestId = null): Transaction
    {
        return app(CreateSale::class)->handle([
            'user_id' => $this->user->id,
            'device_id' => $this->device->id,
            'client_request_id' => $clientRequestId ?? (string) Str::uuid(),
            'paid_minor' => $quantity * 5000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'unit_price_minor' => 5000,
                'conversion_factor' => 1,
            ]],
        ]);
    }

    private function pusher(LoopbackSyncTransport $transport): PushOutbox
    {
        return new PushOutbox(
            $this->tenant->id,
            $this->device->id,
            $transport,
            new Outbox($this->tenant->id),
        );
    }

    // ------------------------------------------------------------- penjualan lokal

    public function test_offline_sales_are_saved_locally_and_queued_for_sync(): void
    {
        $sale = $this->offlineSale(quantity: 2);

        // Penjualan lokal tersimpan meski tidak ada koneksi (§11.1).
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(48, $this->product->refresh()->stock);

        $entry = SyncOutbox::query()->sole();
        $this->assertSame(SyncStatus::Pending, $entry->sync_status);
        $this->assertSame('sale', $entry->entity_type);
        $this->assertSame($sale->uuid, $entry->entity_id);
        $this->assertSame($this->device->id, $entry->device_id);
        // operation_id = client_request_id → satu kunci idempotensi (§11.1).
        $this->assertSame($sale->client_request_id, $entry->operation_id);
        $this->assertSame(2, $entry->payload['items'][0]['quantity']);
    }

    public function test_sale_without_device_does_not_enqueue_an_outbox_operation(): void
    {
        app(CreateSale::class)->handle([
            'user_id' => $this->user->id,
            'paid_minor' => 5000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price_minor' => 5000,
                'conversion_factor' => 1,
            ]],
        ]);

        $this->assertSame(0, SyncOutbox::query()->count());
    }

    // ---------------------------------------------------------------- koneksi putus

    public function test_connection_loss_keeps_operations_pending_and_preserves_local_data(): void
    {
        $this->offlineSale();
        $this->offlineSale();

        $transport = (new LoopbackSyncTransport)->goOffline(1);

        $summary = $this->pusher($transport)->handle($this->token);

        // Hasil tidak diketahui → bukan `failed`, tapi tetap dapat diulang (§11.3).
        $this->assertSame(2, $summary->sent);
        $this->assertSame(0, $summary->synced);
        $this->assertSame(2, $summary->retrying);

        foreach (SyncOutbox::query()->get() as $entry) {
            $this->assertSame(SyncStatus::Pending, $entry->sync_status);
            $this->assertSame(1, $entry->retry_count);
            $this->assertTrue($entry->isRetryable());
        }

        // Data lokal tidak boleh hilang hanya karena pengiriman gagal (§11.5).
        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(48, $this->product->refresh()->stock);
        $this->assertSame(0, SyncInbox::query()->count());
    }

    public function test_status_report_shows_pending_operations_during_the_outage(): void
    {
        $this->offlineSale();
        (new LoopbackSyncTransport)->goOffline(1);

        $summary = (new SyncStatusReport($this->tenant->id, $this->device->id))->summary();

        $this->assertSame('pending', $summary['state']);
        $this->assertSame(1, $summary['pending_user_visible']);
        $this->assertCount(1, (new SyncStatusReport($this->tenant->id, $this->device->id))->unsyncedOperations());
    }

    // ------------------------------------------------------------------ koneksi kembali

    public function test_reconnect_syncs_the_whole_queue_to_the_server(): void
    {
        $transport = (new LoopbackSyncTransport)->goOffline(1);

        $this->offlineSale();
        $this->offlineSale();

        // Gagal dulu.
        $failed = $this->pusher($transport)->handle($this->token);
        $this->assertSame(2, $failed->retrying);

        // Koneksi kembali: jeda backoff dilewati dengan memundurkan waktu.
        $this->travel(5)->seconds();

        $summary = $this->pusher($transport)->handle($this->token);

        $this->assertSame(2, $summary->sent);
        $this->assertSame(2, $summary->synced);
        $this->assertSame(0, $summary->retrying);

        $this->assertSame(2, $transport->attempts);
        $this->assertSame(
            0,
            SyncOutbox::query()->where('sync_status', '!=', SyncStatus::Synced->value)->count(),
        );

        // Server menerima kedua operasi; idempoten → tetap 2 transaksi.
        $this->assertSame(2, SyncInbox::query()->count());
        $this->assertSame(
            2,
            SyncInbox::query()->where('status', SyncInboxStatus::Applied->value)->count(),
        );
        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(48, $this->product->refresh()->stock);
    }

    public function test_reconnect_marks_the_report_as_synced(): void
    {
        $transport = (new LoopbackSyncTransport)->goOffline(1);

        $this->offlineSale();

        $this->pusher($transport)->handle($this->token);
        $this->travel(5)->seconds();
        $this->pusher($transport)->handle($this->token);

        $summary = (new SyncStatusReport($this->tenant->id, $this->device->id))->summary();

        $this->assertSame('synced', $summary['state']);
        $this->assertSame(1, $summary['synced']);
        $this->assertSame(0, $summary['pending_user_visible']);
        $this->assertNotNull($summary['last_synced_at']);
    }

    // -------------------------------------------------------------------- idempotensi

    public function test_resending_the_same_operation_after_reconnect_does_not_duplicate_the_sale(): void
    {
        $clientRequestId = (string) Str::uuid();
        $sale = $this->offlineSale(clientRequestId: $clientRequestId);

        $transport = new LoopbackSyncTransport;

        // Putaran pertama: tersinkron.
        $this->pusher($transport)->handle($this->token);
        $this->assertSame(SyncStatus::Synced, SyncOutbox::query()->sole()->sync_status);

        // Perangkat mengirim ulang (mis. crash setelah kirim, sebelum menandai).
        SyncOutbox::query()->sole()->update([
            'sync_status' => SyncStatus::Pending->value,
            'retry_count' => 0,
        ]);

        $summary = $this->pusher($transport)->handle($this->token);

        $this->assertSame(1, $summary->synced);
        // Server mengenali duplikat: inbox tetap satu baris, transaksi tetap satu.
        $this->assertSame(1, SyncInbox::query()->count());
        $this->assertSame(1, Transaction::query()->where('client_request_id', $clientRequestId)->count());
        $this->assertSame($sale->uuid, Transaction::query()->sole()->uuid);
    }
}
