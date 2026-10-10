<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Tenant;
use App\Support\Alerts\AlertGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertGeneratorTest extends TestCase
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

    private static int $productSeq = 0;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        self::$productSeq++;

        return Product::factory()->create(array_merge([
            'stock' => 5,
            'min_stock' => 10,
            'is_unlimited_stock' => false,
            'price' => 15000,
            'barcode' => '899'.str_pad((string) self::$productSeq, 10, '0', STR_PAD_LEFT),
        ], $attributes));
    }

    private static int $customerSeq = 0;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openReceivable(array $attributes = []): Receivable
    {
        self::$customerSeq++;

        return Receivable::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'customer_id' => Customer::create(['name' => 'Budi Santoso '.self::$customerSeq])->id,
            'transaction_id' => 1, // tanpa FK; placeholder cukup untuk data alert
            'original_amount_minor' => 5000,
            'remaining_minor' => 5000,
            'status' => 'open',
        ], $attributes));
    }

    public function test_generates_low_stock_alert_with_payload_and_dedup_key(): void
    {
        $product = $this->product();

        $created = $this->generator()->stockLow();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('alerts', [
            'tenant_id' => $this->tenant->id,
            'alert_type' => 'stock.low',
            'deduplication_key' => "stock.low:{$product->id}",
            'severity' => 'warning',
        ]);

        $alert = Alert::where('deduplication_key', "stock.low:{$product->id}")->first();
        $this->assertTrue(Str::isUuid($alert->uuid));
        $this->assertNull($alert->read_at);
        $this->assertSame($product->id, $alert->payload['product_id']);
        $this->assertSame(5, $alert->payload['stock']);
        $this->assertSame(10, $alert->payload['min_stock']);
        $this->assertSame('Sesuaikan stok', $alert->payload['action']);
        $this->assertSame('/products', $alert->payload['target']);
    }

    public function test_does_not_duplicate_unread_alert(): void
    {
        $this->product();

        $this->assertSame(1, $this->generator()->stockLow());
        $this->assertSame(0, $this->generator()->stockLow());

        $this->assertSame(1, Alert::where('alert_type', 'stock.low')->count());
    }

    public function test_new_alert_respawns_after_previous_one_is_read(): void
    {
        $product = $this->product();

        $this->generator()->stockLow();

        $alert = Alert::where('deduplication_key', "stock.low:{$product->id}")->first();
        $alert->markAsRead();
        $this->assertNotNull($alert->refresh()->read_at);

        $this->assertSame(1, $this->generator()->stockLow());

        $this->assertSame(2, Alert::where('deduplication_key', "stock.low:{$product->id}")->count());
    }

    public function test_no_alert_when_stock_above_minimum(): void
    {
        $this->product(['stock' => 15, 'min_stock' => 10]);

        $this->assertSame(0, $this->generator()->stockLow());
        $this->assertSame(0, Alert::count());
    }

    public function test_ignores_unlimited_stock_and_zero_minimum(): void
    {
        $this->product(['stock' => 0, 'min_stock' => 10, 'is_unlimited_stock' => true]);
        $this->product(['stock' => 0, 'min_stock' => 0, 'is_unlimited_stock' => false]);

        $this->assertSame(0, $this->generator()->stockLow());
        $this->assertSame(0, Alert::count());
    }

    public function test_generates_alert_for_product_without_price_or_barcode(): void
    {
        $product = $this->product(['price' => 0, 'barcode' => null]);

        $created = $this->generator()->incompleteProducts();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('alerts', [
            'tenant_id' => $this->tenant->id,
            'alert_type' => 'product.incomplete_data',
            'deduplication_key' => "product.incomplete:{$product->id}",
        ]);

        $alert = Alert::where('deduplication_key', "product.incomplete:{$product->id}")->first();
        $this->assertFalse($alert->payload['has_price']);
        $this->assertFalse($alert->payload['has_barcode']);
        $this->assertSame('Lengkapi data produk', $alert->payload['action']);
        $this->assertSame('/products', $alert->payload['target']);
    }

    public function test_no_incomplete_alert_when_price_and_barcode_present(): void
    {
        $this->product(['price' => 15000, 'barcode' => '8991234567890']);

        $this->assertSame(0, $this->generator()->incompleteProducts());
        $this->assertSame(0, Alert::count());
    }

    public function test_generates_alert_for_receivable_past_due(): void
    {
        $receivable = $this->openReceivable(['due_at' => now()->subDay()]);

        $created = $this->generator()->receivablesDue();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('alerts', [
            'tenant_id' => $this->tenant->id,
            'alert_type' => 'receivable.due',
            'deduplication_key' => "receivable.due:{$receivable->id}",
            'severity' => 'critical',
        ]);

        $alert = Alert::where('deduplication_key', "receivable.due:{$receivable->id}")->first();
        $this->assertSame($receivable->uuid, $alert->payload['receivable_id']);
        $this->assertSame(5000, $alert->payload['remaining_minor']);
    }

    public function test_no_alert_for_receivable_not_due_yet(): void
    {
        $this->openReceivable(['due_at' => now()->addWeek()]);

        $this->assertSame(0, $this->generator()->receivablesDue());
        $this->assertSame(0, Alert::count());
    }

    public function test_no_alert_for_closed_receivable_or_missing_due_date(): void
    {
        $this->openReceivable(['due_at' => now()->subDay(), 'status' => 'closed']);
        $this->openReceivable(['due_at' => null]);

        $this->assertSame(0, $this->generator()->receivablesDue());
        $this->assertSame(0, Alert::count());
    }

    public function test_alerts_respect_tenant_boundary(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->set($otherTenant);
        Product::factory()->create([
            'stock' => 0,
            'min_stock' => 5,
            'is_unlimited_stock' => false,
            'price' => 15000,
            'barcode' => '8991234567890',
        ]);
        app(TenantContext::class)->set($this->tenant);

        $this->assertSame(0, $this->generator()->stockLow());
        $this->assertSame(0, Alert::count());
    }

    public function test_console_command_generates_alerts_for_all_tenants(): void
    {
        $this->product(['stock' => 2, 'min_stock' => 5]);

        $this->artisan('pos:alerts:generate')
            ->expectsOutputToContain('1 alert')
            ->assertExitCode(0);

        $this->assertDatabaseHas('alerts', [
            'tenant_id' => $this->tenant->id,
            'alert_type' => 'stock.low',
        ]);
    }
}
