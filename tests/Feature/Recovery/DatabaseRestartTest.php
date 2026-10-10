<?php

namespace Tests\Feature\Recovery;

use App\Actions\Sales\CreateSale;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Uji restart (Sprint 3 item 9): data operasional harus bertahan setelah
 * aplikasi/koneksi di-restart. Berbeda dari suite lain, test ini memakai
 * SQLite BERBASIS FILE sehingga `DB::purge()` benar-benar membuang seluruh
 * state PHP dan `DB::reconnect()` membaca ulang dari disk — menirukan proses
 * yang berhenti lalu hidup kembali (plan §23, Fase 0 \"Aplikasi tetap dapat
 * membuka data setelah restart\").
 */
class DatabaseRestartTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'rf_restart_');

        config()->set('database.connections.restart_db', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'restart_db');

        DB::purge('restart_db');

        Artisan::call('migrate', ['--database' => 'restart_db', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('restart_db');

        if (isset($this->databasePath) && is_file($this->databasePath)) {
            @unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_operational_data_persists_across_a_restart_and_reconciles(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create();
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => 15000,
            'is_unlimited_stock' => false,
        ]);

        $sale = app(CreateSale::class)->handle($this->payload($user, $product));
        $saleId = $sale->id;

        // --- Restart: buang semua koneksi & state, lalu sambung ulang ke file yang sama.
        DB::purge('restart_db');
        DB::reconnect('restart_db');

        // Konteks tenant adalah state in-memory; pada restart nyata ia dihidrasi ulang.
        app(TenantContext::class)->set(Tenant::query()->findOrFail($tenant->id));

        $freshSale = Transaction::query()->findOrFail($saleId);
        $this->assertSame('paid', $freshSale->payment_status);
        $this->assertSame(30000, $freshSale->total_minor);
        $this->assertSame(1, $freshSale->items()->count());

        $this->assertSame(8, Product::query()->findOrFail($product->id)->stock);

        // Rekonsiliasi: saldo stok == stok awal + total ledger mutasi.
        $ledger = (int) StockMovement::query()
            ->where('product_id', $product->id)
            ->sum('quantity_change');

        $this->assertSame(8, 10 + $ledger);

        // Audit juga bertahan.
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.created', 'entity_id' => $sale->uuid]);
    }

    public function test_completed_checkout_is_replay_safe_after_restart(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create();
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => 15000,
            'is_unlimited_stock' => false,
        ]);

        $payload = $this->payload($user, $product);
        $first = app(CreateSale::class)->handle($payload);

        // Restart, lalu klien mengirim ulang request yang sama (respons hilang).
        DB::purge('restart_db');
        DB::reconnect('restart_db');
        app(TenantContext::class)->set(Tenant::query()->findOrFail($tenant->id));

        $second = app(CreateSale::class)->handle($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, StockMovement::query()->where('product_id', $product->id)->count());
        $this->assertSame(8, Product::query()->findOrFail($product->id)->stock);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, Product $product): array
    {
        return [
            'user_id' => $user->id,
            'payment_method' => 'cash',
            'paid_minor' => 30000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price_minor' => 15000],
            ],
        ];
    }
}
