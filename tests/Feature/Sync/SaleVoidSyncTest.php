<?php

namespace Tests\Feature\Sync;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Actions\Sales\CreateSale;
use App\Actions\Sales\VoidSale;
use App\Actions\Sync\ApplySyncOperations;
use App\Enums\SyncInboxStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Product;
use App\Models\Receivable;
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
 * Pembatalan terkontrol dari perangkat (§17.4 + §11.1/§11.3).
 *
 * Perangkat yang membatalkan penjualan tanpa jaringan harus dapat mengirim
 * operasi `sale`/`update` yang:
 *  - diterapkan sebagai void domain yang sama dengan jalur web;
 *  - idempoten (tidak mengembalikan stok dua kali);
 *  - ditolak dengan alasan jelas bila tidak sah (tanpa alasan, penjualan belum
 *    ada di server, piutang sudah dibayar);
 *  - tidak dipantulkan kembali ke outbox perangkat.
 */
class SaleVoidSyncTest extends TestCase
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
     * @param  array<string, mixed>  $payloadOverrides
     * @return array<string, mixed>
     */
    private function createOperation(
        array $payloadOverrides = [],
        string $operationId = '',
        string $entityId = '',
    ): array {
        return [
            'operation_id' => $operationId !== '' ? $operationId : (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => $entityId !== '' ? $entityId : (string) Str::uuid(),
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
        ];
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     * @return array<string, mixed>
     */
    private function voidOperation(Transaction $transaction, array $payloadOverrides = []): array
    {
        return [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => $transaction->uuid,
            'operation_type' => 'update',
            'payload' => array_merge([
                'void' => true,
                'user_id' => $this->user->id,
                'reason' => 'Pelanggan membatalkan pesanan',
                'restore_stock' => true,
            ], $payloadOverrides),
            'local_sequence' => 2,
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

    private function pushedSale(): Transaction
    {
        $this->push([$this->createOperation()])->assertOk();

        return Transaction::query()->sole();
    }

    // ------------------------------------------------------- penerapan void

    public function test_a_device_void_is_applied_to_the_domain(): void
    {
        $transaction = $this->pushedSale();

        $this->assertSame(8, $this->product->refresh()->stock);

        $op = $this->voidOperation($transaction);

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.entity_id', $transaction->uuid);

        $transaction->refresh();
        $this->assertSame('void', $transaction->payment_status);
        $this->assertSame('Pelanggan membatalkan pesanan', $transaction->void_reason);
        $this->assertSame($this->user->id, $transaction->voided_by);
        $this->assertTrue($transaction->stock_restored);

        // Mutasi balik, bukan penghapusan.
        $this->assertSame(10, $this->product->refresh()->stock);
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_void')->count());
        $this->assertSame(1, $transaction->items()->count());

        $audit = AuditLog::where('action', 'sale.voided')->sole();
        $this->assertSame($this->device->id, $audit->device_id);
        $this->assertSame($op['operation_id'], $audit->metadata['client_request_id']);
        $this->assertSame($this->tenant->id, $audit->tenant_id);

        $this->assertSame(
            SyncInboxStatus::Applied,
            SyncInbox::where('operation_id', $op['operation_id'])->sole()->status,
        );
    }

    public function test_repushing_the_void_does_not_restore_stock_twice(): void
    {
        $transaction = $this->pushedSale();
        $op = $this->voidOperation($transaction);

        $this->push([$op])->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->push([$op])->assertOk()
            ->assertJson(['accepted' => 0, 'duplicates' => 1])
            ->assertJsonPath('results.0.status', 'applied');

        $this->assertSame(10, $this->product->refresh()->stock);
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_void')->count());
        $this->assertSame(1, AuditLog::where('action', 'sale.voided')->count());
    }

    public function test_applying_the_void_twice_is_idempotent(): void
    {
        $transaction = $this->pushedSale();
        $op = $this->voidOperation($transaction);

        $this->push([$op])->assertOk();

        // Putaran penerapan kedua tidak menemukan baris `received`.
        $statuses = (new ApplySyncOperations($this->tenant->id, $this->device->id))->handle();

        $this->assertSame('applied', $statuses[$op['operation_id']]['status']);
        $this->assertSame(10, $this->product->refresh()->stock);
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_void')->count());
    }

    public function test_the_server_adopts_the_device_assigned_sale_identity(): void
    {
        $entityId = (string) Str::uuid();
        $create = $this->createOperation(operationId: '', entityId: $entityId);

        $this->push([$create])
            ->assertOk()
            ->assertJsonPath('results.0.entity_id', $entityId);

        // §17.1/§11.1: identitas penjualan sama di server dan di perangkat.
        $this->assertSame($entityId, Transaction::query()->sole()->uuid);
    }

    public function test_the_same_sale_identity_is_never_created_twice(): void
    {
        $entityId = (string) Str::uuid();

        $first = $this->createOperation(entityId: $entityId);
        $second = $this->createOperation(entityId: $entityId);
        $second['payload']['user_id'] = $this->user->id;

        $this->push([$first])->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->push([$second])->assertOk()->assertJsonPath('results.0.status', 'applied');

        // Operasi berbeda, identitas sama → tetap satu penjualan (idempoten).
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(8, $this->product->refresh()->stock);
    }

    public function test_a_sale_with_a_non_uuid_identity_is_rejected(): void
    {
        $create = $this->createOperation(entityId: 'bukan-uuid');

        $this->push([$create])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(10, $this->product->refresh()->stock);
    }

    public function test_create_and_void_in_one_batch_are_applied_in_order(): void
    {
        $create = $this->createOperation();
        $entityId = Str::uuid();

        // Perangkat menetapkan uuid penjualannya sendiri, lalu membatalkannya
        // dalam batch yang sama (§11.3 langkah 5: urutan dijaga).
        $create['entity_id'] = $entityId;
        $create['operation_id'] = (string) Str::uuid();

        $void = [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => $entityId,
            'operation_type' => 'update',
            'payload' => [
                'void' => true,
                'user_id' => $this->user->id,
                'reason' => 'Salah input di perangkat',
                'restore_stock' => true,
            ],
            'local_sequence' => 2,
        ];

        $this->push([$create, $void])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied');

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('void', Transaction::query()->sole()->payment_status);
        $this->assertSame(10, $this->product->refresh()->stock);
    }

    // -------------------------------------------------------- penolakan sah

    public function test_a_void_without_a_reason_is_rejected(): void
    {
        $transaction = $this->pushedSale();

        $op = $this->voidOperation($transaction, ['reason' => '   ']);

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame('paid', $transaction->refresh()->payment_status);
        $this->assertSame(8, $this->product->refresh()->stock);
        $this->assertSame(
            SyncInboxStatus::Rejected,
            SyncInbox::where('operation_id', $op['operation_id'])->sole()->status,
        );
    }

    public function test_an_update_without_a_void_declaration_is_rejected(): void
    {
        $transaction = $this->pushedSale();

        $op = [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => $transaction->uuid,
            'operation_type' => 'update',
            'payload' => ['user_id' => $this->user->id, 'paid_minor' => 999],
            'local_sequence' => 2,
        ];

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame('paid', $transaction->refresh()->payment_status);
    }

    public function test_voiding_a_sale_that_does_not_exist_yet_is_rejected(): void
    {
        $op = [
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => (string) Str::uuid(),
            'operation_type' => 'update',
            'payload' => ['void' => true, 'user_id' => $this->user->id, 'reason' => 'Tidak ada'],
            'local_sequence' => 1,
        ];

        $this->push([$op])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.error', 'Penjualan yang akan dibatalkan belum ada di server; kirim penjualannya lebih dahulu.');
    }

    public function test_voiding_a_sale_with_recorded_receivable_payments_is_rejected(): void
    {
        $customer = Customer::create(['name' => 'Budi Santoso', 'tenant_id' => $this->tenant->id]);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'payment_method' => 'cash',
            'paid_minor' => 4000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price_minor' => 10000],
            ],
            'enqueue_for_sync' => false,
        ]);

        app(RecordReceivablePayment::class)->handle([
            'receivable_id' => Receivable::where('transaction_id', $sale->id)->sole()->id,
            'amount_minor' => 1000,
            'user_id' => $this->user->id,
        ]);

        $this->push([$this->voidOperation($sale)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame('credit', $sale->refresh()->payment_status);
        $this->assertSame(0, StockMovement::where('reference_type', 'sale_void')->count());
    }

    // ------------------------------------------------------- sisi perangkat

    public function test_a_void_on_a_device_enqueues_one_update_operation(): void
    {
        $sale = app(CreateSale::class)->handle([
            'user_id' => $this->user->id,
            'device_id' => $this->device->id,
            'payment_method' => 'cash',
            'paid_minor' => 5000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price_minor' => 5000],
            ],
            'enqueue_for_sync' => false,
        ]);

        app(VoidSale::class)->handle([
            'transaction_id' => $sale->id,
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->device->id,
            'reason' => 'Salah input',
            'client_request_id' => 'op-void-1',
        ]);

        $outbox = SyncOutbox::query()->sole();

        $this->assertSame('sale', $outbox->entity_type);
        $this->assertSame($sale->uuid, $outbox->entity_id);
        $this->assertSame('update', $outbox->operation_type);
        $this->assertSame('op-void-1', $outbox->operation_id);
        $this->assertTrue($outbox->payload['void']);
        $this->assertSame('Salah input', $outbox->payload['reason']);
        $this->assertTrue($outbox->payload['restore_stock']);
    }

    public function test_applying_a_void_does_not_echo_an_operation_back_to_the_outbox(): void
    {
        $transaction = $this->pushedSale();

        $this->assertSame(0, SyncOutbox::query()->count());

        $this->push([$this->voidOperation($transaction)])->assertOk();

        // Operasi perangkat tidak boleh dipantulkan kembali (§11.1).
        $this->assertSame(0, SyncOutbox::query()->count());
    }
}
