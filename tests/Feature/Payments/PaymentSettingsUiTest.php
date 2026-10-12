<?php

namespace Tests\Feature\Payments;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\QrisPayloadFactory;
use Tests\TestCase;

/**
 * UI pengaturan QRIS statis (FR-05 / FR-12).
 *
 * Halaman hanya untuk pemegang izin `payment_gateway.configure`; konfigurasi
 * terisolasi per tenant dan fail-closed untuk akun tanpa tenant.
 */
class PaymentSettingsUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Flux memakai @teleport (multi root) — matikan debug agar render
        // Livewire tidak ditolak.
        config(['app.debug' => false]);

        $this->tenant = Tenant::factory()->create();

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole('cashier');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('settings.payment'))->assertRedirect(route('login'));
    }

    public function test_an_actor_without_permission_is_forbidden(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('settings.payment'))
            ->assertForbidden();
    }

    public function test_owner_sees_the_settings_page_and_the_instruction_notice(): void
    {
        $this->actingAs($this->owner)
            ->get(route('settings.payment'))
            ->assertOk()
            ->assertSee('Payment Settings')
            ->assertSee('QRIS statis hanya instruksi pembayaran')
            ->assertSee('Belum dikonfigurasi');
    }

    public function test_a_user_without_a_tenant_sees_a_fail_closed_notice(): void
    {
        $orphan = User::factory()->create(['tenant_id' => null]);
        $orphan->assignRole('owner');

        $this->actingAs($orphan)
            ->get(route('settings.payment'))
            ->assertOk()
            ->assertSee('Akun belum terhubung ke tenant');
    }

    public function test_it_shows_the_saved_qris_configuration(): void
    {
        $this->tenant->update([
            'qris_payload' => QrisPayloadFactory::static('TOKO TERKONFIGURASI'),
            'qris_merchant_name' => 'TOKO TERKONFIGURASI',
        ]);

        $this->actingAs($this->owner)
            ->get(route('settings.payment'))
            ->assertOk()
            ->assertSee('TOKO TERKONFIGURASI')
            ->assertSee('Aktif');
    }

    public function test_owner_can_save_a_valid_payload_from_the_page(): void
    {
        $payload = QrisPayloadFactory::static('TOKO BARU', 'MEDAN');

        Livewire::actingAs($this->owner)
            ->test('settings.payment')
            ->set('qris_payload', $payload)
            ->set('reason', 'Pasang QRIS')
            ->call('save')
            ->assertHasNoErrors();

        $this->tenant->refresh();

        $this->assertSame($payload, $this->tenant->qris_payload);
        $this->assertSame('TOKO BARU', $this->tenant->qris_merchant_name);

        $this->assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::PaymentGatewayConfigured->value)->count(),
        );
    }

    public function test_an_invalid_payload_shows_a_validation_error_and_saves_nothing(): void
    {
        Livewire::actingAs($this->owner)
            ->test('settings.payment')
            ->set('qris_payload', 'tentu-bukan-qris')
            ->call('save')
            ->assertHasErrors(['qris_payload']);

        $this->assertNull($this->tenant->refresh()->qris_payload);
        $this->assertSame(0, AuditLog::query()->count());
    }

    /**
     * Middleware rute TIDAK berlaku pada `Livewire::test`; yang menjaga di sini
     * adalah otorisasi di aksi domain (`ConfigureQris` memeriksa izin). Jadi
     * kasir boleh merender komponen, tetapi penyimpanan tetap ditolak.
     */
    public function test_a_cashier_cannot_save_through_the_component(): void
    {
        Livewire::actingAs($this->cashier)
            ->test('settings.payment')
            ->set('qris_payload', QrisPayloadFactory::static())
            ->call('save');

        $this->assertNull($this->tenant->refresh()->qris_payload);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_owner_can_clear_the_configuration(): void
    {
        $this->tenant->update([
            'qris_payload' => QrisPayloadFactory::static('TOKO LAMA'),
            'qris_merchant_name' => 'TOKO LAMA',
        ]);

        Livewire::actingAs($this->owner)
            ->test('settings.payment')
            ->call('clear')
            ->assertHasNoErrors();

        $this->tenant->refresh();

        $this->assertNull($this->tenant->qris_payload);
        $this->assertFalse($this->tenant->hasQrisConfigured());
    }

    public function test_a_user_without_a_tenant_cannot_save(): void
    {
        $orphan = User::factory()->create(['tenant_id' => null]);
        $orphan->assignRole('owner');

        Livewire::actingAs($orphan)
            ->test('settings.payment')
            ->set('qris_payload', QrisPayloadFactory::static())
            ->call('save');

        // Tidak ada QRIS yang tertulis di tenant mana pun.
        $this->assertSame(0, Tenant::query()->whereNotNull('qris_payload')->count());
        $this->assertNull($this->tenant->refresh()->qris_payload);
    }
}
