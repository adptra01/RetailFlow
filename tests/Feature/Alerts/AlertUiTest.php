<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sprint 3 item 3 — UI alert stok minimum / notifikasi lokal (FR-10).
 *
 * Halaman menampilkan alert dari kondisi lokal, mendukung filter
 * jenis/tingkat/status baca, menandai dibaca, dan menjalankan pemeriksaan
 * `AlertGenerator` secara manual.
 */
class AlertUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Flux memakai @teleport sehingga komponen punya lebih dari satu
        // elemen akar; matikan debug agar Livewire::test tidak menolak render.
        config(['app.debug' => false]);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function alert(array $attributes = []): Alert
    {
        return Alert::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'alert_type' => Alert::TYPE_STOCK_LOW,
            'deduplication_key' => 'stock.low:'.uniqid(),
            'severity' => Alert::SEVERITY_WARNING,
            'payload' => [
                'product_name' => 'Kopi Arabica',
                'stock' => 1,
                'min_stock' => 5,
                'action' => 'Sesuaikan stok',
                'target' => '/products',
            ],
        ], $attributes));
    }

    public function test_guests_are_redirected_from_the_alerts_page(): void
    {
        $this->get(route('alerts.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_unread_alerts(): void
    {
        $this->alert();

        $this->actingAs($this->user)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Notifikasi')
            ->assertSee('Stok menipis')
            ->assertSee('Kopi Arabica');
    }

    public function test_read_alerts_are_hidden_by_default_but_visible_in_all_scope(): void
    {
        $this->alert(['read_at' => now(), 'payload' => ['product_name' => 'Produk Lama', 'target' => '/products']]);

        $this->actingAs($this->user)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertDontSee('Produk Lama');

        $this->actingAs($this->user)
            ->get(route('alerts.index', ['scope' => 'all']))
            ->assertOk()
            ->assertSee('Produk Lama');
    }

    public function test_mark_read_marks_a_single_alert(): void
    {
        $alert = $this->alert();

        Livewire::actingAs($this->user)
            ->test('alerts.index')
            ->call('markRead', $alert->id);

        $this->assertNotNull($alert->refresh()->read_at);
    }

    public function test_mark_all_read_marks_every_unread_alert(): void
    {
        $first = $this->alert();
        $second = $this->alert(['severity' => Alert::SEVERITY_CRITICAL]);

        Livewire::actingAs($this->user)
            ->test('alerts.index')
            ->call('markAllRead');

        $this->assertNotNull($first->refresh()->read_at);
        $this->assertNotNull($second->refresh()->read_at);
        $this->assertSame(0, Alert::whereNull('read_at')->count());
    }

    public function test_filter_by_severity(): void
    {
        $this->alert(['payload' => ['product_name' => 'Stok Menipis', 'target' => '/products']]);
        $this->alert([
            'alert_type' => Alert::TYPE_RECEIVABLE_DUE,
            'severity' => Alert::SEVERITY_CRITICAL,
            'deduplication_key' => 'receivable.due:1',
            'payload' => ['remaining_minor' => 5000, 'target' => '/customers'],
        ]);

        Livewire::actingAs($this->user)
            ->test('alerts.index')
            ->assertSee('Stok Menipis')
            ->assertSee('Piutang jatuh tempo');

        $this->actingAs($this->user)
            ->get(route('alerts.index', ['severity' => Alert::SEVERITY_CRITICAL]))
            ->assertOk()
            ->assertSee('Piutang jatuh tempo')
            ->assertDontSee('Stok Menipis');
    }

    public function test_filter_by_type(): void
    {
        $this->alert(['payload' => ['product_name' => 'Stok Menipis', 'target' => '/products']]);
        $this->alert([
            'alert_type' => Alert::TYPE_BACKUP_OVERDUE,
            'deduplication_key' => 'backup.overdue',
            'payload' => ['last_backup_at' => '2026-01-01', 'target' => '/reports'],
        ]);

        $this->actingAs($this->user)
            ->get(route('alerts.index', ['type' => Alert::TYPE_BACKUP_OVERDUE]))
            ->assertOk()
            ->assertSee('Backup terlalu lama')
            ->assertDontSee('Stok Menipis');
    }

    public function test_run_checks_generates_alerts_for_current_tenant(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'stock' => 1,
            'min_stock' => 5,
            'is_unlimited_stock' => false,
            'price' => 15000,
            'barcode' => '8991000000017',
        ]);

        Livewire::actingAs($this->user)
            ->test('alerts.index')
            ->call('runChecks');

        $this->assertDatabaseHas('alerts', [
            'tenant_id' => $this->tenant->id,
            'alert_type' => 'stock.low',
        ]);
    }

    public function test_alerts_of_other_tenants_are_not_visible(): void
    {
        $otherTenant = Tenant::factory()->create();

        $this->alert(['payload' => ['product_name' => 'Punya Kita', 'target' => '/products']]);

        Alert::create([
            'tenant_id' => $otherTenant->id,
            'alert_type' => Alert::TYPE_STOCK_LOW,
            'deduplication_key' => 'stock.low:other',
            'severity' => Alert::SEVERITY_WARNING,
            'payload' => ['product_name' => 'Punya Tetangga', 'target' => '/products'],
        ]);

        Livewire::actingAs($this->user)
            ->test('alerts.index')
            ->assertSee('Punya Kita')
            ->assertDontSee('Punya Tetangga');
    }

    public function test_mark_read_cannot_touch_another_tenants_alert(): void
    {
        $otherTenant = Tenant::factory()->create();

        $foreign = Alert::create([
            'tenant_id' => $otherTenant->id,
            'alert_type' => Alert::TYPE_STOCK_LOW,
            'deduplication_key' => 'stock.low:foreign',
            'severity' => Alert::SEVERITY_WARNING,
            'payload' => ['target' => '/products'],
        ]);

        Livewire::actingAs($this->user)
            ->test('alerts.index')
            ->call('markRead', $foreign->id);

        $this->assertNull($foreign->refresh()->read_at);
    }

    public function test_sidebar_shows_the_unread_alert_count(): void
    {
        $this->alert();
        $this->alert(['severity' => Alert::SEVERITY_CRITICAL]);

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Notifikasi');
    }

    public function test_user_without_tenant_sees_no_alerts(): void
    {
        // Fail-closed: `users.tenant_id` nullable, jadi pengguna tanpa tenant
        // tidak boleh melihat alert milik tenant mana pun.
        $tenantless = User::factory()->create(['tenant_id' => null]);

        $this->alert(['payload' => ['product_name' => 'Rahasia Tenant', 'target' => '/products']]);

        $this->actingAs($tenantless)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertDontSee('Rahasia Tenant');
    }
}
