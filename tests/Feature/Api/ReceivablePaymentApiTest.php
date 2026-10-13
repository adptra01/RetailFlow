<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `POST /api/v1/receivables/{id}/payments` (§18, FR-07) — Increment 55.
 *
 * Penagihan piutang dari klien perangkat: nominal dalam minor unit, saldo
 * piutang dijawab server sesudah pembayaran, tidak boleh menagih dua kali
 * (idempotensi), dan piutang tenant lain tidak pernah terlihat (§12).
 *
 * Profil fitur `credit_sales` dipakai karena modul `receivables` memang hanya
 * ada pada profil itu (FR-13).
 */
class ReceivablePaymentApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private string $token;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create(['feature_profile' => 'credit_sales']);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        app(TenantContext::class)->set($this->tenant);
        $this->token = $this->owner->createToken('Kasir Kredit')->plainTextToken;

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Kopi Bubuk',
            'slug' => 'kopi-bubuk',
            'price' => 25000.00,
            'stock' => 10,
        ]);

        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * Meniru keadaan awal setiap permintaan HTTP pada aplikasi sungguhan.
     */
    private function freshRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->set(null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pay(string $reference, array $payload, ?string $token = null): TestResponse
    {
        $this->freshRequestState();

        return $this->postJson('/api/v1/receivables/'.$reference.'/payments', $payload, [
            'Authorization' => 'Bearer '.($token ?? $this->token),
        ]);
    }

    /**
     * Penjualan kredit lewat API: total 25.000, dibayar 10.000, sisa 15.000.
     *
     * @return array{0: int, 1: string, 2: string} id, uuid, dan id transaksi
     */
    private function creditSale(int $paidMinor = 10000, int $quantity = 1): array
    {
        $this->freshRequestState();

        $response = $this->postJson('/api/v1/sales', [
            'client_request_id' => (string) Str::uuid7(),
            'customer_id' => $this->customer->getKey(),
            'items' => [['product_id' => $this->product->uuid, 'quantity' => $quantity]],
            'paid_minor' => $paidMinor,
            'payment_method' => 'cash',
        ], ['Authorization' => 'Bearer '.$this->token])->assertCreated();

        return [
            (int) $response->json('sale.receivable.id'),
            (string) $response->json('sale.receivable.uuid'),
            (string) $response->json('sale.receivable.remaining_minor'),
        ];
    }

    public function test_it_records_a_payment_and_reports_the_updated_receivable(): void
    {
        [$receivableId, $receivableUuid, $remaining] = $this->creditSale();

        $this->assertSame('15000', $remaining);

        $response = $this->pay($receivableUuid, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 5000,
            'payment_method' => 'transfer',
            'notes' => 'Setoran pertama',
        ])->assertCreated();

        $response->assertJsonPath('payment.amount_minor', 5000);
        $response->assertJsonPath('payment.payment_method', 'transfer');
        $response->assertJsonPath('payment.payment_method_label', 'Transfer Bank');
        $response->assertJsonPath('payment.receivable_id', $receivableId);
        $response->assertJsonPath('payment.notes', 'Setoran pertama');
        $response->assertJsonPath('payment.is_reversed', false);
        $response->assertJsonPath('payment.recorded_by', $this->owner->getKey());
        $response->assertJsonPath('receivable.id', $receivableId);
        $response->assertJsonPath('receivable.remaining_minor', 10000);
        $response->assertJsonPath('receivable.status', 'open');
        $response->assertJsonPath('sync.status', 'applied');
        $response->assertJsonPath('sync.idempotent_replay', false);
        $response->assertJsonPath('currency.minor_exponent', 0);

        $this->assertNotNull($response->json('server_time'));
        $this->assertNotNull($response->json('payment.paid_at'));

        $this->assertSame(
            1,
            (int) DB::table('receivable_payments')->where('tenant_id', $this->tenant->id)->count(),
        );
        $this->assertSame(
            10000,
            (int) DB::table('receivables')->where('id', $receivableId)->value('remaining_minor'),
        );
    }

    public function test_a_settlement_closes_the_receivable(): void
    {
        [$receivableId] = $this->creditSale();

        $this->pay((string) $receivableId, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 15000,
            'payment_method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('receivable.remaining_minor', 0)
            ->assertJsonPath('receivable.status', 'closed');

        $this->assertSame(
            'closed',
            (string) DB::table('receivables')->where('id', $receivableId)->value('status'),
        );
    }

    public function test_replaying_the_same_client_request_id_does_not_charge_twice(): void
    {
        [$receivableId] = $this->creditSale();
        $key = (string) Str::uuid7();

        $first = $this->pay((string) $receivableId, [
            'client_request_id' => $key,
            'amount_minor' => 5000,
            'payment_method' => 'cash',
        ])->assertCreated();

        $second = $this->pay((string) $receivableId, [
            'client_request_id' => $key,
            'amount_minor' => 5000,
            'payment_method' => 'cash',
        ]);

        $second->assertOk()
            ->assertJsonPath('sync.idempotent_replay', true)
            ->assertJsonPath('sync.status', 'already_applied');

        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
        $this->assertSame(10000, $second->json('receivable.remaining_minor'));
        $this->assertSame(
            1,
            (int) DB::table('receivable_payments')->where('tenant_id', $this->tenant->id)->count(),
        );
    }

    public function test_overpayment_is_refused(): void
    {
        [$receivableId] = $this->creditSale();

        // Sisa hanya 15.000; menagih 20.000 berarti mengubah saldo secara tidak
        // sah (FR-07: saldo piutang tidak boleh diubah dengan mengedit angka).
        $this->pay((string) $receivableId, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 20000,
            'payment_method' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pembayaran melebihi sisa piutang.');

        $this->assertSame(0, (int) DB::table('receivable_payments')->count());
    }

    public function test_a_closed_receivable_cannot_be_paid_again(): void
    {
        [$receivableId] = $this->creditSale();

        $this->pay((string) $receivableId, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 15000,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->pay((string) $receivableId, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Piutang tidak ditemukan atau sudah lunas.');

        $this->assertSame(1, (int) DB::table('receivable_payments')->count());
    }

    public function test_receivables_of_other_tenants_are_hidden(): void
    {
        [, $receivableUuid] = $this->creditSale();

        $neighbour = Tenant::factory()->create(['feature_profile' => 'credit_sales']);
        $neighbourOwner = User::factory()->create(['tenant_id' => $neighbour->id]);
        $neighbourOwner->assignRole('owner');
        $neighbourToken = $neighbourOwner->createToken('Kasir B')->plainTextToken;

        $this->pay($receivableUuid, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
        ], $neighbourToken)->assertNotFound();

        // Rujukan yang tidak ada / tidak berbentuk id pun 404, bukan bocor.
        $this->pay('bukan-rujukan', [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
        ])->assertNotFound();

        $this->pay((string) Str::uuid7(), [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
        ])->assertNotFound();

        $this->assertSame(0, (int) DB::table('receivable_payments')->count());
    }

    public function test_the_request_contract_is_reported_per_field(): void
    {
        [$receivableId] = $this->creditSale();

        $this->pay((string) $receivableId, [
            'amount_minor' => 1000,
            'payment_method' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_request_id');

        $this->pay((string) $receivableId, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 0,
            'payment_method' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_minor');

        // `mixed` hanya deskripsi hasil, bukan metode yang boleh dipilih (FR-05).
        $this->pay((string) $receivableId, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 1000,
            'payment_method' => 'mixed',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->pay((string) $receivableId, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
            'device_id' => (string) Str::uuid7(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_id');

        $this->assertSame(0, (int) DB::table('receivable_payments')->count());
    }

    public function test_the_receivables_module_must_be_enabled(): void
    {
        [$receivableId] = $this->creditSale();

        $this->tenant->update(['enabled_modules' => ['cashier', 'products']]);

        $this->pay((string) $receivableId, [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Modul piutang tidak aktif untuk toko ini.');

        $this->assertSame(0, (int) DB::table('receivable_payments')->count());
    }

    public function test_the_endpoint_requires_a_user_token(): void
    {
        [$receivableId] = $this->creditSale();

        $this->freshRequestState();

        $this->postJson('/api/v1/receivables/'.$receivableId.'/payments', [])->assertStatus(401);

        // Token PERANGKAT tidak berlaku sebagai token pengguna (§15).
        $deviceToken = (string) $this->postJson('/api/v1/devices/register', [
            'name' => 'Kasir Kredit 1',
            'platform' => 'android',
        ], ['Authorization' => 'Bearer '.$this->token])->assertStatus(201)->json('token');

        $this->freshRequestState();

        $this->postJson('/api/v1/receivables/'.$receivableId.'/payments', [
            'client_request_id' => (string) Str::uuid7(),
            'amount_minor' => 1000,
            'payment_method' => 'cash',
        ], ['Authorization' => 'Bearer '.$deviceToken])->assertStatus(401);
    }
}
