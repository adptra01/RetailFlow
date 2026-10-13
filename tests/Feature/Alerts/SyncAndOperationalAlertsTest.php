<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Device;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\Alerts\AlertGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Increment 48 — melengkapi kategori alert FR-10: sinkronisasi tertunda/gagal,
 * perangkat belum tersinkron, pembayaran menunggu verifikasi, dan selisih stok.
 */
class SyncAndOperationalAlertsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);
    }

    private function generator(): AlertGenerator
    {
        return new AlertGenerator($this->tenant->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function device(array $attributes = []): Device
    {
        return Device::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'created_at' => now()->subDays(3),
            'last_seen_at' => now()->subDays(2),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function outbox(Device $device, array $attributes = []): SyncOutbox
    {
        static $seq = 0;
        $seq++;

        $entry = new SyncOutbox;
        $entry->forceFill(array_merge([
            'operation_id' => (string) Str::uuid7(),
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'entity_type' => 'sale',
            'entity_id' => (string) $seq,
            'operation_type' => 'create',
            'payload' => [],
            'local_sequence' => $seq,
            'sync_status' => 'pending',
            'retry_count' => 0,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ], $attributes))->saveQuietly();

        return $entry;
    }

    public function test_creates_sync_backlog_alert_for_pending_operations_past_threshold(): void
    {
        $device = $this->device(['device_name' => 'Kasir Depan']);
        $this->outbox($device);
        $this->outbox($device);

        $created = $this->generator()->syncBacklog();

        $this->assertSame(1, $created);

        $alert = Alert::where('alert_type', Alert::TYPE_SYNC_BACKLOG)->sole();
        $this->assertSame($this->tenant->id, $alert->tenant_id);
        $this->assertSame($device->id, $alert->device_id);
        $this->assertSame("sync.backlog:{$device->id}", $alert->deduplication_key);
        $this->assertSame(2, $alert->payload['pending_count']);
        $this->assertSame('Kasir Depan', $alert->payload['device_name']);
        $this->assertSame('/sync', $alert->payload['target']);
    }

    public function test_no_sync_backlog_alert_for_fresh_or_synced_operations(): void
    {
        $device = $this->device();

        // Baru dibuat: belum melewati ambang.
        $this->outbox($device, ['created_at' => now(), 'updated_at' => now()]);
        // Sudah tersinkron: bukan backlog.
        $this->outbox($device, ['sync_status' => 'synced']);

        $this->assertSame(0, $this->generator()->syncBacklog());
        $this->assertSame(0, Alert::count());
    }

    public function test_sync_backlog_alert_is_deduplicated_until_read(): void
    {
        $device = $this->device();
        $this->outbox($device);

        $this->assertSame(1, $this->generator()->syncBacklog());
        $this->assertSame(0, $this->generator()->syncBacklog());

        Alert::where('alert_type', Alert::TYPE_SYNC_BACKLOG)->sole()->markAsRead();

        $this->assertSame(1, $this->generator()->syncBacklog());
    }

    public function test_creates_sync_failed_alert_grouped_by_device(): void
    {
        $device = $this->device(['device_name' => 'Kasir Belakang']);
        $this->outbox($device, ['sync_status' => 'failed', 'retry_count' => 5]);
        $this->outbox($device, ['sync_status' => 'failed', 'retry_count' => 5]);

        $created = $this->generator()->syncFailed();

        $this->assertSame(1, $created);

        $alert = Alert::where('alert_type', Alert::TYPE_SYNC_FAILED)->sole();
        $this->assertSame($device->id, $alert->device_id);
        $this->assertSame(Alert::SEVERITY_CRITICAL, $alert->severity);
        $this->assertSame(2, $alert->payload['failed_count']);
    }

    public function test_creates_stale_device_alert_when_not_seen_recently(): void
    {
        $device = $this->device([
            'device_name' => 'Tablet Gudang',
            'created_at' => now()->subDays(5),
            'last_seen_at' => now()->subDays(3),
        ]);

        $created = $this->generator()->staleDevices();

        $this->assertSame(1, $created);

        $alert = Alert::where('alert_type', Alert::TYPE_DEVICE_STALE)->sole();
        $this->assertSame($device->id, $alert->device_id);
        $this->assertSame(Alert::SEVERITY_WARNING, $alert->severity);
        $this->assertSame('/sync', $alert->payload['target']);
    }

    public function test_stale_device_alert_excludes_fresh_seen_and_revoked_devices(): void
    {
        // Baru dibuat: dikecualikan agar pendaftaran baru tidak memicu noise.
        $this->device(['created_at' => now(), 'last_seen_at' => null]);
        // Baru saja menyapa server.
        $this->device(['created_at' => now()->subDays(5), 'last_seen_at' => now()]);
        // Sudah dicabut: tidak diperiksa lagi.
        $this->device([
            'created_at' => now()->subDays(5),
            'last_seen_at' => now()->subDays(5),
            'revoked_at' => now()->subDay(),
        ]);

        $this->assertSame(0, $this->generator()->staleDevices());
        $this->assertSame(0, Alert::count());
    }

    public function test_creates_payment_awaiting_verification_alert_past_threshold(): void
    {
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-QRIS-1',
            'payment_status' => 'paid',
            'payment_verification' => 'awaiting_verification',
            'payment_method' => 'qris',
            'created_at' => now()->subHour(),
        ]);

        $created = $this->generator()->paymentsAwaitingVerification();

        $this->assertSame(1, $created);

        $alert = Alert::where('alert_type', Alert::TYPE_PAYMENT_UNVERIFIED)->sole();
        $this->assertSame($transaction->uuid, $alert->payload['transaction_id']);
        $this->assertSame('INV-QRIS-1', $alert->payload['invoice_number']);
        $this->assertSame(Alert::SEVERITY_CRITICAL, $alert->severity);
    }

    public function test_payment_alert_ignores_fresh_verified_and_void_transactions(): void
    {
        // Baru: belum melewati ambang.
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'payment_verification' => 'awaiting_verification',
            'created_at' => now(),
        ]);
        // Sudah terverifikasi.
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'payment_verification' => 'verified',
            'created_at' => now()->subHour(),
        ]);
        // Dibatalkan.
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'payment_status' => 'void',
            'payment_verification' => 'awaiting_verification',
            'created_at' => now()->subHour(),
        ]);

        $this->assertSame(0, $this->generator()->paymentsAwaitingVerification());
        $this->assertSame(0, Alert::count());
    }

    public function test_creates_stock_discrepancy_alert_per_product(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'stock' => 10,
            'min_stock' => 0,
            'price' => 15000,
            'barcode' => '8990000000001',
        ]);

        StockOpname::create([
            'product_id' => $product->id,
            'system_stock' => 10,
            'actual_stock' => 7,
            'difference' => -3,
            'reason' => 'Rusak',
            'user_id' => null,
        ]);

        $created = $this->generator()->stockDiscrepancies();

        $this->assertSame(1, $created);

        $alert = Alert::where('alert_type', Alert::TYPE_STOCK_DISCREPANCY)->sole();
        $this->assertSame($this->tenant->id, $alert->tenant_id);
        $this->assertSame("stock.discrepancy:{$product->id}", $alert->deduplication_key);
        $this->assertSame(-3, $alert->payload['difference']);
        $this->assertSame('/products', $alert->payload['target']);
    }

    public function test_stock_discrepancy_ignores_matching_stock_and_other_tenants(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        // Stok cocok: bukan selisih.
        StockOpname::create([
            'product_id' => $product->id,
            'system_stock' => 10,
            'actual_stock' => 10,
            'difference' => 0,
        ]);

        // Produk tenant lain: tidak boleh bocor.
        $other = Tenant::factory()->create();
        $foreignProduct = Product::factory()->create(['tenant_id' => $other->id]);
        StockOpname::create([
            'product_id' => $foreignProduct->id,
            'system_stock' => 5,
            'actual_stock' => 2,
            'difference' => -3,
        ]);

        $this->assertSame(0, $this->generator()->stockDiscrepancies());
        $this->assertSame(0, Alert::count());
    }

    public function test_sync_alert_does_not_leak_across_tenants(): void
    {
        $other = Tenant::factory()->create();
        $foreignDevice = Device::factory()->create(['tenant_id' => $other->id]);
        $this->outbox($foreignDevice);

        $this->assertSame(0, $this->generator()->syncBacklog());
        $this->assertSame(0, Alert::count());
    }

    public function test_new_categories_fail_closed_without_tenant(): void
    {
        app(TenantContext::class)->set(null);

        $generator = new AlertGenerator;

        $this->assertSame(0, $generator->syncBacklog());
        $this->assertSame(0, $generator->syncFailed());
        $this->assertSame(0, $generator->staleDevices());
        $this->assertSame(0, $generator->paymentsAwaitingVerification());
        $this->assertSame(0, $generator->stockDiscrepancies());
        $this->assertSame(0, Alert::count());
    }

    public function test_run_includes_new_categories(): void
    {
        $device = $this->device(['last_seen_at' => now()]);
        $this->outbox($device, ['sync_status' => 'failed', 'retry_count' => 5]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'stock' => 10,
            'min_stock' => 0,
            'price' => 15000,
            'barcode' => '8990000000002',
        ]);
        StockOpname::create([
            'product_id' => $product->id,
            'system_stock' => 4,
            'actual_stock' => 1,
            'difference' => -3,
        ]);

        $created = $this->generator()->run();

        $this->assertSame(2, $created);
        $this->assertDatabaseHas('alerts', ['alert_type' => Alert::TYPE_SYNC_FAILED]);
        $this->assertDatabaseHas('alerts', ['alert_type' => Alert::TYPE_STOCK_DISCREPANCY]);
    }
}
