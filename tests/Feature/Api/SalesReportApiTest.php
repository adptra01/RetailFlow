<?php

namespace Tests\Feature\Api;

use App\Enums\AuditAction;
use App\Models\Category;
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
 * `GET /api/v1/reports/sales` (§18, FR-09) — Increment 55.
 *
 * Endpoint ini memanggil use case laporan yang sama dengan UI, jadi yang
 * diuji di sini adalah kontrak API-nya: periode (satu hari atau rentang),
 * isolasi tenant (§12), izin FR-12 `report.financial.view` beserta jejak
 * auditnya, dan bahwa metrik yang dijawab memang definisi FR-09 (omzet ≠
 * penerimaan, transaksi batal tidak dihitung).
 */
class SalesReportApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private string $token;

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
        $this->token = $this->owner->createToken('Laporan 1')->plainTextToken;

        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Teh Kotak',
            'slug' => 'teh-kotak',
            'price' => 10000.00,
            'stock' => 50,
        ]);
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
    private function request(string $uri, array $payload = [], ?string $token = null): TestResponse
    {
        $this->freshRequestState();

        $headers = ['Authorization' => 'Bearer '.($token ?? $this->token)];

        return $payload === []
            ? $this->getJson($uri, $headers)
            : $this->postJson($uri, $payload, $headers);
    }

    /**
     * Penjualan tunai penuh lewat API (harga dari master: 10.000/unit).
     */
    private function sell(int $quantity = 1, ?string $token = null): TestResponse
    {
        return $this->request('/api/v1/sales', [
            'client_request_id' => (string) Str::uuid7(),
            'items' => [['product_id' => $this->product->uuid, 'quantity' => $quantity]],
            'payments' => [['method' => 'cash', 'amount_minor' => 10000 * $quantity]],
        ], $token)->assertCreated();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function report(array $query = [], ?string $token = null): TestResponse
    {
        $uri = '/api/v1/reports/sales';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->request($uri, token: $token);
    }

    private function backdate(string $invoiceNumber, string $date): void
    {
        DB::table('transactions')
            ->where('invoice_number', $invoiceNumber)
            ->update(['created_at' => $date.' 10:00:00']);
    }

    public function test_it_reports_today_by_default(): void
    {
        $this->sell(2);

        $response = $this->report()->assertOk();

        $response->assertJsonPath('report.from', now()->toDateString());
        $response->assertJsonPath('report.to', now()->toDateString());
        $response->assertJsonPath('report.omzet_minor', 20000);
        $response->assertJsonPath('report.penerimaan_minor', 20000);
        $response->assertJsonPath('report.piutang_baru_minor', 0);
        $response->assertJsonPath('report.transaksi_count', 1);
        $response->assertJsonPath('report.rata_rata_transaksi_minor', 20000);
        $response->assertJsonPath('report.dibatalkan_count', 0);
        $response->assertJsonPath('report.metode_pembayaran.0.payment_method', 'cash');
        $response->assertJsonPath('report.metode_pembayaran.0.transaksi', 1);
        $response->assertJsonPath('report.riwayat.0.total_minor', 20000);
        $response->assertJsonPath('currency.minor_exponent', 0);

        $this->assertCount(1, $response->json('report.riwayat'));
        $this->assertNotNull($response->json('report.riwayat.0.invoice_number'));
        $this->assertNotNull($response->json('server_time'));
    }

    public function test_it_reports_a_single_day_and_a_range(): void
    {
        $today = $this->sell();
        $yesterday = $this->sell();

        $this->backdate((string) $yesterday->json('sale.invoice_number'), now()->subDay()->toDateString());

        $this->report(['date' => now()->toDateString()])
            ->assertOk()
            ->assertJsonPath('report.transaksi_count', 1)
            ->assertJsonPath('report.riwayat.0.invoice_number', $today->json('sale.invoice_number'));

        $this->report(['date' => now()->subDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('report.transaksi_count', 1)
            ->assertJsonPath('report.riwayat.0.invoice_number', $yesterday->json('sale.invoice_number'));

        $this->report([
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('report.transaksi_count', 2)
            ->assertJsonPath('report.omzet_minor', 20000);
    }

    public function test_voided_sales_are_excluded_from_revenue(): void
    {
        $sale = $this->sell();

        $this->request('/api/v1/sales/'.$sale->json('sale.uuid').'/void', [
            'reason' => 'Salah input kasir',
        ])->assertOk();

        $response = $this->report()->assertOk();

        $response->assertJsonPath('report.omzet_minor', 0);
        $response->assertJsonPath('report.penerimaan_minor', 0);
        $response->assertJsonPath('report.dibatalkan_count', 1);
        $response->assertJsonPath('report.dibatalkan_minor', 10000);
        // §17.4: penjualan yang dibatalkan tetap tampil di riwayat + alasannya.
        $response->assertJsonPath('report.riwayat.0.void_reason', 'Salah input kasir');
        $this->assertNotNull($response->json('report.riwayat.0.voided_at'));
    }

    public function test_it_requires_the_financial_report_permission(): void
    {
        $this->sell();

        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $manager->assignRole('inventory_manager');
        $managerToken = $manager->createToken('Inventori 1')->plainTextToken;

        $this->report(token: $managerToken)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Aksi memerlukan izin «Melihat laporan keuangan» (report.financial.view).');

        // FR-12: laporan yang benar-benar dibuka dicatat bersama aktornya.
        $this->assertSame(0, (int) DB::table('audit_logs')
            ->where('action', AuditAction::FinancialReportViewed->value)
            ->count());

        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $viewer->assignRole('viewer');
        $viewerToken = $viewer->createToken('Pengamat 1')->plainTextToken;

        $this->report(token: $viewerToken)->assertOk();

        $this->assertSame(1, (int) DB::table('audit_logs')
            ->where('action', AuditAction::FinancialReportViewed->value)
            ->where('actor_id', $viewer->getKey())
            ->count());
    }

    public function test_the_reports_module_must_be_enabled(): void
    {
        $this->tenant->update(['enabled_modules' => ['cashier', 'products']]);

        $this->report()
            ->assertStatus(403)
            ->assertJsonPath('message', 'Modul laporan tidak aktif untuk toko ini.');
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $this->report(['date' => '13-10-2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->report(['from' => 'kemarin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    public function test_other_tenants_sales_are_not_included(): void
    {
        $this->sell();

        $neighbour = Tenant::factory()->create(['feature_profile' => 'retail']);
        $neighbourCategory = Category::factory()->create(['tenant_id' => $neighbour->id]);
        $neighbourProduct = Product::factory()->create([
            'tenant_id' => $neighbour->id,
            'category_id' => $neighbourCategory->id,
            'name' => 'Produk Tetangga',
            'price' => 50000.00,
            'stock' => 10,
        ]);
        $neighbourOwner = User::factory()->create(['tenant_id' => $neighbour->id]);
        $neighbourOwner->assignRole('owner');
        $neighbourToken = $neighbourOwner->createToken('Kasir B')->plainTextToken;

        $this->request('/api/v1/sales', [
            'client_request_id' => (string) Str::uuid7(),
            'items' => [['product_id' => $neighbourProduct->uuid, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount_minor' => 50000]],
        ], $neighbourToken)->assertCreated();

        $this->report()
            ->assertOk()
            ->assertJsonPath('report.omzet_minor', 10000)
            ->assertJsonPath('report.transaksi_count', 1);

        $this->report(token: $neighbourToken)
            ->assertOk()
            ->assertJsonPath('report.omzet_minor', 50000)
            ->assertJsonPath('report.transaksi_count', 1);
    }

    public function test_the_endpoint_requires_a_user_token(): void
    {
        $this->sell();

        $this->freshRequestState();
        $this->getJson('/api/v1/reports/sales')->assertStatus(401);

        // Token PERANGKAT tidak berlaku sebagai token pengguna (§15).
        $deviceToken = (string) $this->request('/api/v1/devices/register', [
            'name' => 'Tablet Laporan',
            'platform' => 'android',
        ])->assertStatus(201)->json('token');

        $this->freshRequestState();
        $this->getJson('/api/v1/reports/sales', [
            'Authorization' => 'Bearer '.$deviceToken,
        ])->assertStatus(401);
    }
}
