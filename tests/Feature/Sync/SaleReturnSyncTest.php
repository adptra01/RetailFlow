<?php

namespace Tests\Feature\Sync;

use App\Actions\Sales\CreateSale;
use App\Actions\Sales\CreateSalesReturn;
use App\Enums\SyncInboxStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\SyncInbox;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Devices\DeviceToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Retur penjualan dari perangkat (§11.1–§11.3, §17.4, FR-08).
 *
 * Perangkat yang menerima pengembalian barang tanpa jaringan harus dapat
 * mengirim operasi `sale_return`/`create` yang:
 *  - diterapkan sebagai dokumen retur domain yang sama dengan jalur web;
 *  - memakai identitas retur yang ditetapkan perangkat (§17.1);
 *  - idempoten — tidak mengembalikan stok atau mengembalikan uang dua kali;
 *  - ditolak dengan alasan jelas bila tidak sah (tanpa alasan, penjualan belum
 *    ada di server, baris tidak cocok);
 *  - tidak dipantulkan kembali ke outbox perangkat.
 */
class SaleReturnSyncTest extends TestCase
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

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'stock' => 10,
            'price' => 5000,
            'is_unlimited_stock' => false,
        ]);
    }

    /**
     * Penjualan yang berasal dari perangkat (seperti §11.3 langkah 3), agar
     * retur yang dikirim belakangan merujuk penjualan nyata di server.
     */
    private function pushedSale(int $quantity = 2): Transaction
    {
        $create = [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => (string) Str::uuid(),
            'operation_type' => 'create',
            'payload' => [
                'user_id' => $this->user->id,
                'paid_minor' => 5000 * $quantity,
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

        $this->push([$create])->assertOk();

        return Transaction::query()->sole();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $payloadOverrides
     * @return array<string, mixed>
     */
    private function returnOperation(
        Transaction $transaction,
        array $items,
        array $payloadOverrides = [],
        string $entityId = '',
        string $operationId = '',
    ): array {
        return [
            'operation_id' => $operationId !== '' ? $operationId : (string) Str::uuid(),
            'entity_type' => 'sale_return',
            'entity_id' => $entityId !== '' ? $entityId : (string) Str::uuid(),
            'operation_type' => 'create',
            'payload' => array_merge([
                'transaction_uuid' => $transaction->uuid,
                'user_id' => $this->user->id,
                'reason' => 'Barang tidak sesuai pesanan',
                'restore_stock' => true,
                'items' => $items,
            ], $payloadOverrides),
            'local_sequence' => 2,
        ];
    }

    /**
     * @return array{transaction_item_id: int, quantity: int}
     */
    private function line(Transaction $transaction, int $quantity): array
    {
        return [
            'transaction_item_id' => $transaction->items()->firstOrFail()->id,
            'quantity' => $quantity,
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

    public function test_a_device_return_is_applied_to_the_domain(): void
    {
        $transaction = $this->pushedSale(quantity: 2);

        $this->assertSame(8, $this->product->refresh()->stock);

        $op = $this->returnOperation($transaction, [$this->line($transaction, 2)]);

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.entity_id', $op['entity_id']);

        $return = SalesReturn::query()->sole();

        // §17.1: identitas dokumen retur milik perangkat diadopsi server.
        $this->assertSame($op['entity_id'], $return->uuid);
        $this->assertSame($transaction->id, $return->transaction_id);
        $this->assertSame('Barang tidak sesuai pesanan', $return->reason);
        $this->assertSame(10000, $return->total_minor);
        $this->assertSame(10000, $return->refund_minor);
        $this->assertSame(0, $return->receivable_adjustment_minor);
        $this->assertTrue($return->stock_restored);
        $this->assertSame($this->device->id, $return->device_id);
        $this->assertSame($op['operation_id'], $return->client_request_id);
        $this->assertSame($this->tenant->id, $return->tenant_id);

        // Stok kembali lewat mutasi yang dapat ditelusuri (FR-08).
        $this->assertSame(10, $this->product->refresh()->stock);
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_return')->count());

        $audit = AuditLog::where('action', 'sale.returned')->sole();
        $this->assertSame($this->device->id, $audit->device_id);
        $this->assertSame($op['operation_id'], $audit->metadata['client_request_id']);
        $this->assertSame($this->tenant->id, $audit->tenant_id);

        $this->assertSame(
            SyncInboxStatus::Applied,
            SyncInbox::where('operation_id', $op['operation_id'])->sole()->status,
        );
    }

    public function test_the_payload_can_reference_the_line_by_its_uuid(): void
    {
        $transaction = $this->pushedSale(quantity: 2);
        $line = $transaction->items()->firstOrFail();

        $op = $this->returnOperation($transaction, [[
            'transaction_item_uuid' => $line->uuid,
            'quantity' => 1,
        ]]);

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied');

        $this->assertSame(1, SalesReturn::query()->sole()->items()->sole()->qty);
        $this->assertSame(9, $this->product->refresh()->stock);
    }

    public function test_repushing_the_return_does_not_restore_stock_twice(): void
    {
        $transaction = $this->pushedSale(quantity: 2);
        $op = $this->returnOperation($transaction, [$this->line($transaction, 2)]);

        $this->push([$op])->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->push([$op])->assertOk()
            ->assertJson(['accepted' => 0, 'duplicates' => 1])
            ->assertJsonPath('results.0.status', 'applied');

        $this->assertSame(10, $this->product->refresh()->stock);
        $this->assertSame(1, SalesReturn::query()->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_return')->count());
        $this->assertSame(1, AuditLog::where('action', 'sale.returned')->count());
    }

    public function test_a_different_operation_with_the_same_return_identity_is_not_duplicated(): void
    {
        $transaction = $this->pushedSale(quantity: 2);
        $entityId = (string) Str::uuid();

        $first = $this->returnOperation($transaction, [$this->line($transaction, 2)], entityId: $entityId);
        $second = $this->returnOperation($transaction, [$this->line($transaction, 2)], entityId: $entityId);

        $this->push([$first])->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->push([$second])->assertOk()->assertJsonPath('results.0.status', 'applied');

        // Operasi berbeda, identitas dokumen sama → tetap satu retur (§17.1).
        $this->assertSame(1, SalesReturn::query()->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_return')->count());
        $this->assertSame(10, $this->product->refresh()->stock);
    }

    public function test_a_return_without_a_reason_is_rejected(): void
    {
        $transaction = $this->pushedSale();

        $op = $this->returnOperation($transaction, [$this->line($transaction, 1)], ['reason' => '   ']);

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, SalesReturn::query()->count());
        $this->assertSame(8, $this->product->refresh()->stock);
        $this->assertSame(
            SyncInboxStatus::Rejected,
            SyncInbox::where('operation_id', $op['operation_id'])->sole()->status,
        );
    }

    public function test_returning_more_than_the_sale_is_rejected(): void
    {
        $transaction = $this->pushedSale(quantity: 2);

        $op = $this->returnOperation($transaction, [$this->line($transaction, 3)]);

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, SalesReturn::query()->count());
        $this->assertSame(8, $this->product->refresh()->stock);
    }

    public function test_a_return_on_a_sale_that_does_not_exist_yet_is_rejected(): void
    {
        $op = [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale_return',
            'entity_id' => (string) Str::uuid(),
            'operation_type' => 'create',
            'payload' => [
                'transaction_uuid' => (string) Str::uuid(),
                'user_id' => $this->user->id,
                'reason' => 'Barang rusak',
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ],
            'local_sequence' => 1,
        ];

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.error', 'Penjualan yang akan diretur belum ada di server; kirim penjualannya lebih dahulu.');

        $this->assertSame(0, SalesReturn::query()->count());
    }

    public function test_a_return_whose_line_does_not_match_is_rejected(): void
    {
        $transaction = $this->pushedSale();

        $op = $this->returnOperation($transaction, [[
            'transaction_item_id' => 999999,
            'quantity' => 1,
        ]]);

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.error', 'Baris retur tidak dapat dicocokkan dengan penjualan di server.');

        $this->assertSame(0, SalesReturn::query()->count());
        $this->assertSame(8, $this->product->refresh()->stock);
    }

    public function test_a_non_uuid_return_identity_is_rejected(): void
    {
        $transaction = $this->pushedSale();

        $op = $this->returnOperation($transaction, [$this->line($transaction, 1)], entityId: 'bukan-uuid');

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, SalesReturn::query()->count());
    }

    public function test_a_device_return_corrects_a_credit_receivable_before_refunding(): void
    {
        $customer = Customer::create(['name' => 'Budi Santoso', 'tenant_id' => $this->tenant->id]);

        $create = [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => (string) Str::uuid(),
            'operation_type' => 'create',
            'payload' => [
                'user_id' => $this->user->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'paid_minor' => 4000,
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price_minor' => 10000,
                    'conversion_factor' => 1,
                ]],
            ],
            'local_sequence' => 1,
        ];

        $this->push([$create])->assertOk();

        $transaction = Transaction::query()->sole();
        $receivable = Receivable::where('transaction_id', $transaction->id)->sole();

        $this->assertSame(6000, $receivable->remaining_minor);

        $op = $this->returnOperation($transaction, [$this->line($transaction, 1)]);

        $this->push([$op])->assertOk()->assertJsonPath('results.0.status', 'applied');

        $return = SalesReturn::query()->sole();

        // 6000 membatalkan sisa piutang, 4000 kembali sebagai kas (§17.4).
        $this->assertSame(10000, $return->total_minor);
        $this->assertSame(6000, $return->receivable_adjustment_minor);
        $this->assertSame(4000, $return->refund_minor);

        $receivable->refresh();
        $this->assertSame(0, $receivable->remaining_minor);
        $this->assertSame(0, $receivable->original_amount_minor);
        $this->assertSame('closed', $receivable->status);
    }

    public function test_a_line_can_be_referenced_by_product_when_the_device_has_no_server_ids(): void
    {
        $transaction = $this->pushedSale(quantity: 2);

        $op = $this->returnOperation($transaction, [[
            'product_id' => $this->product->id,
            'quantity' => 2,
        ]]);

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied');

        $item = SalesReturn::query()->sole()->items()->sole();

        $this->assertSame($transaction->items()->sole()->id, $item->transaction_item_id);
        $this->assertSame(2, $item->qty);
        $this->assertSame(10, $this->product->refresh()->stock);
    }

    public function test_create_and_return_in_one_batch_are_applied_in_order(): void
    {
        $saleUuid = (string) Str::uuid();

        $create = [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => $saleUuid,
            'operation_type' => 'create',
            'payload' => [
                'user_id' => $this->user->id,
                'paid_minor' => 10000,
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price_minor' => 5000,
                    'conversion_factor' => 1,
                ]],
            ],
            'local_sequence' => 1,
        ];

        // Perangkat meretur penjualan yang baru dibuatnya dalam batch yang
        // sama (§11.3 langkah 5: urutan dijaga). Baris dirujuk lewat produk
        // karena id server belum pernah ditarik perangkat.
        $return = [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale_return',
            'entity_id' => (string) Str::uuid(),
            'operation_type' => 'create',
            'payload' => [
                'transaction_uuid' => $saleUuid,
                'user_id' => $this->user->id,
                'reason' => 'Pelanggan berubah pikiran',
                'restore_stock' => true,
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                ]],
            ],
            'local_sequence' => 2,
        ];

        $this->push([$create, $return])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied');

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, SalesReturn::query()->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_return')->count());
        $this->assertSame(10, $this->product->refresh()->stock);
    }

    public function test_a_return_on_a_device_enqueues_one_create_operation(): void
    {
        $sale = app(CreateSale::class)->handle([
            'user_id' => $this->user->id,
            'device_id' => $this->device->id,
            'payment_method' => 'cash',
            'paid_minor' => 10000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price_minor' => 5000],
            ],
            'enqueue_for_sync' => false,
        ]);

        $line = $sale->items()->sole();

        $return = app(CreateSalesReturn::class)->handle([
            'transaction_id' => $sale->id,
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->device->id,
            'reason' => 'Barang rusak',
            'items' => [['transaction_item_id' => $line->id, 'quantity' => 2]],
            'client_request_id' => 'op-return-1',
        ]);

        $outbox = SyncOutbox::query()->sole();

        $this->assertSame('sale_return', $outbox->entity_type);
        $this->assertSame($return->uuid, $outbox->entity_id);
        $this->assertSame('create', $outbox->operation_type);
        $this->assertSame('op-return-1', $outbox->operation_id);
        $this->assertSame($sale->uuid, $outbox->payload['transaction_uuid']);
        $this->assertSame($this->user->id, $outbox->payload['user_id']);
        $this->assertSame('Barang rusak', $outbox->payload['reason']);
        $this->assertTrue($outbox->payload['restore_stock']);
        $this->assertSame($line->id, $outbox->payload['items'][0]['transaction_item_id']);
        $this->assertSame($line->uuid, $outbox->payload['items'][0]['transaction_item_uuid']);
        $this->assertSame($this->product->id, $outbox->payload['items'][0]['product_id']);
        $this->assertSame(2, $outbox->payload['items'][0]['quantity']);
    }

    public function test_applying_a_return_does_not_echo_an_operation_back_to_the_outbox(): void
    {
        $transaction = $this->pushedSale();

        $this->assertSame(0, SyncOutbox::query()->count());

        $this->push([$this->returnOperation($transaction, [$this->line($transaction, 1)])])->assertOk();

        // Operasi perangkat tidak boleh dipantulkan kembali (§11.1).
        $this->assertSame(0, SyncOutbox::query()->count());
    }
}
