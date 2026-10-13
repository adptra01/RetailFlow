<?php

namespace Tests\Feature\Api;

use App\Enums\Module;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `POST /api/v1/sales`, `GET /api/v1/sales/{id}`, `POST /api/v1/sales/{id}/void`
 * (§18) — Increment 54.
 *
 * Yang diuji di sini adalah janji-janji §18 pada jalur tulis:
 *  - harga TIDAK dipercaya dari klien, dihitung server dari master;
 *  - idempotensi (`client_request_id`/`uuid`) tidak menulis penjualan kedua;
 *  - isolasi tenant (§12) berlaku untuk kueri maupun identitas;
 *  - otorisasi FR-12 dan alasan void (§17.4) ditegakkan;
 *  - jawaban memuat id, nomor struk, total, rincian pembayaran, sisa piutang,
 *    dan status sinkronisasi.
 */
class SaleApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private string $token;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create(['feature_profile' => 'retail']);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        app(TenantContext::class)->set($this->tenant);
        $this->token = $this->owner->createToken('Kasir 1')->plainTextToken;

        $this->category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = $this->makeProduct('Indomie Goreng', 3500.00, 10);
    }

    private function makeProduct(string $name, float $price, int $stock, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => $name,
            'slug' => str()->slug($name),
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => $price,
            'stock' => $stock,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(array $payload, ?string $token = null): TestResponse
    {
        $this->freshRequestState();

        return $this->postJson('/api/v1/sales', $payload, [
            'Authorization' => 'Bearer '.($token ?? $this->token),
        ]);
    }

    private function show(string $reference, ?string $token = null): TestResponse
    {
        $this->freshRequestState();

        return $this->getJson('/api/v1/sales/'.$reference, [
            'Authorization' => 'Bearer '.($token ?? $this->token),
        ]);
    }

    private function void(string $reference, array $payload = ['reason' => 'Salah produk'], ?string $token = null): TestResponse
    {
        $this->freshRequestState();

        return $this->postJson('/api/v1/sales/'.$reference.'/void', $payload, [
            'Authorization' => 'Bearer '.($token ?? $this->token),
        ]);
    }

    /**
     * Meniru keadaan awal setiap permintaan HTTP pada aplikasi sungguhan:
     * guard auth dan konteks tenant dimulai dari kosong. Keduanya `singleton`
     * sehingga tanpa penyetelan ulang akan terbawa antar permintaan di dalam
     * satu tes ("identitas" pengguna sebelumnya bocor ke permintaan
     * berikutnya). Konteks tenant HARUS kosong saat autentikasi karena
     * `BelongsToTenant` menyaring `users` juga — persis seperti permintaan
     * sungguhan, yang mengikat tenant setelah pengguna dikenali.
     */
    private function freshRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->set(null);
    }

    /**
     * Checkout tunai standar: 2 unit produk utama, dibayar 10.000.
     *
     * @return array<string, mixed>
     */
    private function cashPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => (string) Str::uuid7(),
            'items' => [
                ['product_id' => $this->product->uuid, 'quantity' => 2],
            ],
            'payments' => [
                ['method' => 'cash', 'amount_minor' => 10000],
            ],
        ], $overrides);
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->getKey())->value('stock');
    }

    public function test_it_creates_a_sale_with_server_computed_prices_in_minor_units(): void
    {
        $response = $this->store($this->cashPayload())->assertCreated();

        $response->assertJsonPath('sale.total_minor', 7000);
        $response->assertJsonPath('sale.subtotal_minor', 7000);
        $response->assertJsonPath('sale.discount_minor', 0);
        $response->assertJsonPath('sale.paid_minor', 10000);
        $response->assertJsonPath('sale.change_minor', 3000);
        $response->assertJsonPath('sale.status', 'paid');
        $response->assertJsonPath('sale.is_void', false);
        $response->assertJsonPath('sale.items.0.unit_price_minor', 3500);
        $response->assertJsonPath('sale.items.0.quantity', 2);
        $response->assertJsonPath('sale.items.0.product_name', 'Indomie Goreng');
        $response->assertJsonPath('sale.payments.0.method', 'cash');
        $response->assertJsonPath('sale.payments.0.amount_minor', 10000);
        $response->assertJsonPath('sale.receivable', null);
        $response->assertJsonPath('sync.status', 'applied');
        $response->assertJsonPath('sync.idempotent_replay', false);

        // §18: respons memuat nomor struk dan konteks server.
        $this->assertNotNull($response->json('sale.invoice_number'));
        $this->assertNotNull($response->json('server_time'));
        $response->assertJsonPath('currency.minor_exponent', 0);

        $this->assertSame(8, $this->stockOf($this->product));

        $this->assertDatabaseHas('transaction_items', [
            'product_id' => $this->product->getKey(),
            'unit_price_minor' => 3500,
            'subtotal_minor' => 7000,
        ]);
        $this->assertDatabaseHas('transactions', [
            'tenant_id' => $this->tenant->id,
            'client_request_id' => $response->json('sync.client_request_id'),
            'total_minor' => 7000,
        ]);
    }

    public function test_prices_sent_by_the_client_are_ignored(): void
    {
        // Klien nakal mengirim harga sendiri; server harus mengabaikannya.
        $response = $this->store($this->cashPayload([
            'items' => [[
                'product_id' => $this->product->uuid,
                'quantity' => 2,
                'unit_price_minor' => 1,
                'subtotal_minor' => 2,
            ]],
            'payments' => [['method' => 'cash', 'amount_minor' => 7000]],
        ]))->assertCreated();

        $response->assertJsonPath('sale.items.0.unit_price_minor', 3500);
        $response->assertJsonPath('sale.total_minor', 7000);
    }

    public function test_replaying_the_same_client_request_id_returns_the_original_sale(): void
    {
        $payload = $this->cashPayload();

        $first = $this->store($payload)->assertCreated();

        // Badan permintaan berbeda (harga/kuantitas lain) tetapi kunci sama:
        // §11.1 — yang dikembalikan adalah penjualan PERTAMA.
        $second = $this->store($this->cashPayload([
            'client_request_id' => $payload['client_request_id'],
            'items' => [['product_id' => $this->product->uuid, 'quantity' => 5]],
            'payments' => [['method' => 'cash', 'amount_minor' => 17500]],
        ]));

        $second->assertOk()
            ->assertJsonPath('sync.idempotent_replay', true)
            ->assertJsonPath('sync.status', 'already_applied')
            ->assertJsonPath('sale.total_minor', 7000);

        $this->assertSame($first->json('sale.id'), $second->json('sale.id'));
        $this->assertSame(8, $this->stockOf($this->product));
        $this->assertSame(1, (int) DB::table('transactions')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_device_supplied_uuid_is_honoured_and_replayed(): void
    {
        $uuid = (string) Str::uuid7();

        $first = $this->store($this->cashPayload(['uuid' => $uuid]))->assertCreated();
        $this->assertSame($uuid, $first->json('sale.uuid'));

        $second = $this->store($this->cashPayload(['uuid' => $uuid]));

        $second->assertOk()->assertJsonPath('sync.idempotent_replay', true);
        $this->assertSame($uuid, $second->json('sale.uuid'));
        $this->assertSame(8, $this->stockOf($this->product));
    }

    public function test_a_uuid_owned_by_another_tenant_is_refused(): void
    {
        $foreign = Tenant::factory()->create(['feature_profile' => 'retail']);
        $foreignCategory = Category::factory()->create(['tenant_id' => $foreign->id]);
        $foreignProduct = Product::factory()->create([
            'tenant_id' => $foreign->id,
            'category_id' => $foreignCategory->id,
            'price' => 1000.00,
            'stock' => 5,
        ]);

        DB::table('transactions')->insert([
            'uuid' => $uuid = (string) Str::uuid7(),
            'tenant_id' => $foreign->id,
            'customer_name' => 'Umum',
            'invoice_number' => 'INV-FOREIGN-1',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'total_minor' => 1000,
            'paid_minor' => 1000,
            'change_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->store($this->cashPayload([
            'uuid' => $uuid,
            'items' => [['product_id' => $foreignProduct->uuid, 'quantity' => 1]],
        ]))->assertStatus(409);

        // Tidak ada penjualan yang tertulis di tenant kita.
        $this->assertSame(0, (int) DB::table('transactions')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(10, $this->stockOf($this->product));
    }

    public function test_the_same_client_request_id_in_another_tenant_creates_that_tenants_own_sale(): void
    {
        $key = (string) Str::uuid7();

        $mine = $this->store($this->cashPayload(['client_request_id' => $key]))->assertCreated();

        $neighbour = Tenant::factory()->create(['feature_profile' => 'retail']);
        $neighbourCategory = Category::factory()->create(['tenant_id' => $neighbour->id]);
        $neighbourProduct = Product::factory()->create([
            'tenant_id' => $neighbour->id,
            'category_id' => $neighbourCategory->id,
            'name' => 'Produk Tetangga',
            'price' => 1000.00,
            'stock' => 5,
        ]);
        $neighbourOwner = User::factory()->create(['tenant_id' => $neighbour->id]);
        $neighbourOwner->assignRole('owner');
        $neighbourToken = $neighbourOwner->createToken('Kasir B')->plainTextToken;

        $theirs = $this->store([
            'client_request_id' => $key,
            'items' => [['product_id' => $neighbourProduct->uuid, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount_minor' => 1000]],
        ], $neighbourToken)->assertCreated();

        $this->assertNotSame($mine->json('sale.id'), $theirs->json('sale.id'));
        $this->assertNotSame($mine->json('sale.invoice_number'), $theirs->json('sale.invoice_number'));
        $this->assertSame($key, $theirs->json('sync.client_request_id'));

        // Isolasi baca (§12): tenant tetangga tidak bisa melihat penjualan kita.
        $this->show($mine->json('sale.uuid'), $neighbourToken)->assertNotFound();
        $this->show($theirs->json('sale.uuid'))->assertNotFound();
    }

    public function test_a_credit_sale_creates_a_receivable_with_a_due_date(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->store($this->cashPayload([
            'customer_id' => $customer->getKey(),
            'payments' => [['method' => 'cash', 'amount_minor' => 2000]],
            'due_days' => 14,
        ]))->assertCreated();

        $response->assertJsonPath('sale.status', 'credit');
        $response->assertJsonPath('sale.is_credit', true);
        $response->assertJsonPath('sale.outstanding_minor', 5000);
        // Piutang mencatat SISA yang belum dibayar, bukan total penjualan.
        $response->assertJsonPath('sale.receivable.original_amount_minor', 5000);
        $response->assertJsonPath('sale.receivable.remaining_minor', 5000);
        $response->assertJsonPath('sale.customer.id', $customer->getKey());

        $this->assertNotNull($response->json('sale.receivable.due_at'));
        $this->assertSame('open', $response->json('sale.receivable.status'));

        $dueAt = Carbon::parse($response->json('sale.receivable.due_at'));

        $this->assertSame(now()->addDays(14)->toDateString(), $dueAt->toDateString());
    }

    public function test_a_credit_sale_requires_a_customer_of_this_tenant(): void
    {
        // Tanpa pelanggan: tidak ada yang bisa ditagih (FR-07).
        $this->store($this->cashPayload([
            'payments' => [['method' => 'cash', 'amount_minor' => 2000]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih pelanggan untuk penjualan kredit.');

        // Pelanggan tenant lain = pelanggaran isolasi (§12), ditolak validasi.
        $foreign = Customer::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->store($this->cashPayload([
            'customer_id' => $foreign->getKey(),
            'payments' => [['method' => 'cash', 'amount_minor' => 2000]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_inactive_and_foreign_products_are_rejected(): void
    {
        $inactive = $this->makeProduct('Produk Arsip', 1000.00, 5, ['is_active' => false]);

        $this->store($this->cashPayload([
            'items' => [['product_id' => $inactive->uuid, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount_minor' => 1000]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');

        $foreign = $this->makeProduct('Produk Tetangga', 1000.00, 5, [
            'tenant_id' => Tenant::factory()->create()->id,
        ]);

        $this->store($this->cashPayload([
            'items' => [['product_id' => $foreign->uuid, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount_minor' => 1000]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');

        $this->assertSame(0, (int) DB::table('transactions')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_insufficient_stock_is_answered_with_a_clear_validation_message(): void
    {
        $this->store($this->cashPayload([
            'items' => [['product_id' => $this->product->uuid, 'quantity' => 50]],
            'payments' => [['method' => 'cash', 'amount_minor' => 175000]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Stok tidak mencukupi untuk produk #'.$this->product->getKey().': tersedia 10, diminta 50.',
            );

        $this->assertSame(10, $this->stockOf($this->product));
    }

    public function test_discounts_require_the_discount_permission(): void
    {
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $manager->assignRole('inventory_manager');
        $managerToken = $manager->createToken('Manajer 1')->plainTextToken;

        // Tanpa diskon, izin `access.module` saja sudah cukup.
        $this->store($this->cashPayload(), $managerToken)->assertCreated();

        $this->store($this->cashPayload([
            'discount' => ['type' => 'fixed', 'value' => 500],
            'payments' => [['method' => 'cash', 'amount_minor' => 6500]],
        ]), $managerToken)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Aksi memerlukan izin «Memberi diskon» (discount.apply).');
    }

    public function test_a_discount_is_applied_to_the_total(): void
    {
        $response = $this->store($this->cashPayload([
            'discount' => ['type' => 'fixed', 'value' => 700],
            'payments' => [['method' => 'cash', 'amount_minor' => 6300]],
        ]))->assertCreated();

        $response->assertJsonPath('sale.subtotal_minor', 7000);
        $response->assertJsonPath('sale.discount_minor', 700);
        $response->assertJsonPath('sale.total_minor', 6300);
    }

    public function test_the_request_contract_is_required_and_reported_per_field(): void
    {
        $this->store(['items' => [['product_id' => $this->product->uuid, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_request_id');

        $this->store($this->cashPayload([
            'items' => [],
            'payments' => [['method' => 'cash', 'amount_minor' => 1]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->store($this->cashPayload([
            'payments' => [['method' => 'bitcoin', 'amount_minor' => 7000]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payments.0.method');

        $this->store($this->cashPayload([
            'payments' => [['method' => 'cash', 'amount_minor' => 0]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payments.0.amount_minor');

        $this->store($this->cashPayload([
            'device_id' => (string) Str::uuid7(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_id');
    }

    public function test_qris_without_a_configured_payload_is_refused(): void
    {
        $this->store($this->cashPayload([
            'payments' => [['method' => 'qris_static', 'amount_minor' => 7000]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'QRIS belum dikonfigurasi untuk toko ini (tenant '.$this->tenant->id.'). Minta pemilik mengunggah QRIS di Pengaturan Pembayaran.',
            );
    }

    public function test_show_returns_the_sale_by_uuid_or_numeric_id(): void
    {
        $created = $this->store($this->cashPayload())->assertCreated();

        $byUuid = $this->show($created->json('sale.uuid'))->assertOk();
        $byUuid->assertJsonPath('sale.invoice_number', $created->json('sale.invoice_number'));
        $byUuid->assertJsonPath('sale.items.0.unit_price_minor', 3500);
        $byUuid->assertJsonPath('sale.payments.0.method_label', 'Tunai');
        $this->assertNotNull($byUuid->json('server_time'));

        $this->show((string) $created->json('sale.id'))
            ->assertOk()
            ->assertJsonPath('sale.uuid', $created->json('sale.uuid'));

        $this->show('tidak-ada')->assertNotFound();
        $this->show((string) Str::uuid7())->assertNotFound();
    }

    public function test_void_restores_stock_once_and_is_idempotent(): void
    {
        $created = $this->store($this->cashPayload())->assertCreated();
        $uuid = $created->json('sale.uuid');

        $void = $this->void($uuid)->assertOk();

        $void->assertJsonPath('sale.is_void', true);
        $void->assertJsonPath('sale.status', 'void');
        $void->assertJsonPath('sale.void_reason', 'Salah produk');
        $void->assertJsonPath('void.already_void', false);
        $void->assertJsonPath('void.stock_restored', true);
        $void->assertJsonPath('void.restored_items', 1);

        $this->assertSame(10, $this->stockOf($this->product));

        // Transaksi tidak dihapus (§17.4) dan pembatalan ulang tidak menggandakan.
        $this->assertSame(1, (int) DB::table('transactions')->where('tenant_id', $this->tenant->id)->count());

        $again = $this->void($uuid)->assertOk();

        $again->assertJsonPath('void.already_void', true);
        $again->assertJsonPath('void.restored_items', 0);
        $this->assertSame(10, $this->stockOf($this->product));

        $this->show($uuid)->assertJsonPath('sale.is_void', true);
    }

    public function test_void_requires_the_permission_and_a_reason(): void
    {
        $created = $this->store($this->cashPayload())->assertCreated();

        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $manager->assignRole('inventory_manager');
        $managerToken = $manager->createToken('Manajer 1')->plainTextToken;

        $this->void($created->json('sale.uuid'), token: $managerToken)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Aksi memerlukan izin «Membatalkan transaksi» (transaction.void).');

        $this->void($created->json('sale.uuid'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->void($created->json('sale.uuid'), ['reason' => 'ab'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('paid', (string) DB::table('transactions')->where('id', $created->json('sale.id'))->value('payment_status'));
    }

    public function test_void_hides_sales_of_other_tenants(): void
    {
        $created = $this->store($this->cashPayload())->assertCreated();

        $neighbour = Tenant::factory()->create(['feature_profile' => 'retail']);
        $neighbourOwner = User::factory()->create(['tenant_id' => $neighbour->id]);
        $neighbourOwner->assignRole('owner');
        $neighbourToken = $neighbourOwner->createToken('Kasir B')->plainTextToken;

        $this->void($created->json('sale.uuid'), token: $neighbourToken)->assertNotFound();

        $this->assertSame('paid', (string) DB::table('transactions')->where('id', $created->json('sale.id'))->value('payment_status'));
    }

    public function test_a_disabled_cashier_module_blocks_checkout(): void
    {
        $this->tenant->update(['enabled_modules' => [Module::Products->value]]);

        $this->store($this->cashPayload())
            ->assertStatus(403)
            ->assertJsonPath('message', 'Modul kasir tidak aktif untuk toko ini.');

        $this->assertSame(0, (int) DB::table('transactions')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_sales_endpoints_require_a_user_token(): void
    {
        $created = $this->store($this->cashPayload())->assertCreated();

        $this->freshRequestState();
        $this->postJson('/api/v1/sales', [])->assertStatus(401);
        $this->getJson('/api/v1/sales/'.$created->json('sale.uuid'))->assertStatus(401);
        $this->postJson('/api/v1/sales/'.$created->json('sale.uuid').'/void', [])->assertStatus(401);

        // Token PERANGKAT bukan token pengguna (§15): tidak berlaku di sini.
        $deviceToken = (string) $this->postJson('/api/v1/devices/register', [
            'name' => 'Kasir 1',
            'platform' => 'windows',
        ], ['Authorization' => 'Bearer '.$this->token])->assertStatus(201)->json('token');

        $this->freshRequestState();
        $this->getJson('/api/v1/sales/'.$created->json('sale.uuid'), [
            'Authorization' => 'Bearer '.$deviceToken,
        ])->assertStatus(401);

        $this->assertSame(1, Transaction::query()->where('tenant_id', $this->tenant->id)->count());
    }
}
