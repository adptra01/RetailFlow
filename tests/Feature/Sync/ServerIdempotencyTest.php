<?php

namespace Tests\Feature\Sync;

use App\Actions\Sync\ApplySyncOperations;
use App\Enums\SyncInboxStatus;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SyncInbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Devices\DeviceToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 4 item 6 — Idempotensi server (§11.1, §11.3 langkah 6–7, §11.4, §11.5).
 */
class ServerIdempotencyTest extends TestCase
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
            'stock' => 10,
            'is_unlimited_stock' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $payloadOverrides
     * @return array<string, mixed>
     */
    private function saleOperation(array $overrides = [], array $payloadOverrides = []): array
    {
        return array_merge([
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => (string) Str::uuid(),
            'operation_type' => 'create',
            'payload' => array_merge([
                'user_id' => $this->user->id,
                'paid_minor' => 10000,
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price_minor' => 5000,
                    'conversion_factor' => 1,
                ]],
            ], $payloadOverrides),
            'local_sequence' => 1,
        ], $overrides);
    }

    private function push(array $operations)
    {
        return $this->postJson(
            '/api/v1/sync/push',
            ['operations' => $operations],
            ['Authorization' => 'Bearer '.$this->token],
        );
    }

    // ------------------------------------------------------------- penerapan

    public function test_pushed_sale_is_applied_to_the_domain(): void
    {
        $op = $this->saleOperation();

        $response = $this->push([$op]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('accepted', 1);

        $transaction = Transaction::query()->sole();
        $this->assertSame($op['operation_id'], $transaction->client_request_id);
        $this->assertSame($this->tenant->id, $transaction->tenant_id);
        $this->assertSame($this->device->id, $transaction->device_id);
        $this->assertSame(8, $this->product->refresh()->stock);

        $inbox = SyncInbox::query()->sole();
        $this->assertSame(SyncInboxStatus::Applied, $inbox->status);
        $this->assertSame($transaction->uuid, $inbox->applied_entity_id);
        $this->assertNotNull($inbox->applied_at);
    }

    public function test_repush_does_not_duplicate_the_sale(): void
    {
        $op = $this->saleOperation();

        $this->push([$op])->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->push([$op])->assertOk()
            ->assertJson(['accepted' => 0, 'duplicates' => 1])
            ->assertJsonPath('results.0.status', 'applied');

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(8, $this->product->refresh()->stock);
    }

    public function test_apply_is_idempotent_when_run_twice(): void
    {
        $op = $this->saleOperation();
        $this->push([$op])->assertOk();

        // Putaran penerapan kedua tidak menemukan baris `received`.
        $statuses = (new ApplySyncOperations($this->tenant->id, $this->device->id))->handle();

        $this->assertSame('applied', $statuses[$op['operation_id']]['status']);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(8, $this->product->refresh()->stock);
    }

    // ------------------------------------------------------ konflik & tolakan

    public function test_insufficient_stock_becomes_a_conflict_without_side_effects(): void
    {
        $this->product->update(['stock' => 1]);

        $op = $this->saleOperation([], [
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 5,
                'unit_price_minor' => 5000,
                'conversion_factor' => 1,
            ]],
        ]);

        $this->push([$op])->assertOk()->assertJsonPath('results.0.status', 'conflict');

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1, $this->product->refresh()->stock);

        $inbox = SyncInbox::query()->sole();
        $this->assertSame(SyncInboxStatus::Conflict, $inbox->status);
        $this->assertNotNull($inbox->error);
    }

    public function test_invalid_sale_is_rejected(): void
    {
        $op = $this->saleOperation([], ['user_id' => null]);

        $response = $this->push([$op]);

        $response->assertOk()->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, Transaction::query()->count());

        $inbox = SyncInbox::query()->sole();
        $this->assertSame(SyncInboxStatus::Rejected, $inbox->status);
        $this->assertStringContainsString('user_id', (string) $inbox->error);
    }

    public function test_operation_without_handler_is_deferred(): void
    {
        $op = $this->saleOperation(['entity_type' => 'product']);

        $this->push([$op])->assertOk()->assertJsonPath('results.0.status', 'received');

        $this->assertSame(SyncInboxStatus::Received, SyncInbox::query()->sole()->status);
    }

    // ------------------------------------------------------------- pencampuran

    public function test_batch_applies_sales_and_defers_unknown_types(): void
    {
        $sale = $this->saleOperation(['local_sequence' => 1]);
        $deferred = $this->saleOperation(['entity_type' => 'product', 'local_sequence' => 2]);

        $response = $this->push([$sale, $deferred]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'received');

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(2, SyncInbox::query()->count());
    }

    public function test_statuses_are_isolated_per_device(): void
    {
        $op = $this->saleOperation();
        $this->push([$op])->assertOk();

        $other = Device::factory()->create(['tenant_id' => $this->tenant->id]);

        $statuses = (new ApplySyncOperations($this->tenant->id, $other->id))->handle();

        $this->assertSame([], $statuses);
    }
}
