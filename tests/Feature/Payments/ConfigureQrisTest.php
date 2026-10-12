<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\ConfigureQris;
use App\Enums\AuditAction;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Payments\InvalidQrisPayload;
use App\Exceptions\Tenants\TenantNotFound;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\QrisPayloadFactory;
use Tests\TestCase;

/**
 * Aksi domain konfigurasi QRIS statis (FR-05 / FR-12).
 *
 * Yang dibuktikan: hanya pemegang izin `payment_gateway.configure` yang bisa
 * mengubah, payload wajib QRIS statis Indonesia yang sah, dan jejak audit
 * tidak menyimpan payload penuh.
 */
class ConfigureQrisTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole('cashier');
    }

    private function action(): ConfigureQris
    {
        return new ConfigureQris($this->tenant->id);
    }

    public function test_owner_can_configure_a_static_qris_payload(): void
    {
        $payload = QrisPayloadFactory::static('TOKO MAKMUR JAYA', 'BANDUNG');

        $tenant = $this->action()->configure($this->owner, $payload);

        $this->assertSame($payload, $tenant->qris_payload);
        $this->assertSame('TOKO MAKMUR JAYA', $tenant->qris_merchant_name);
        $this->assertTrue($tenant->hasQrisConfigured());

        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'qris_merchant_name' => 'TOKO MAKMUR JAYA',
        ]);
    }

    public function test_merchant_name_falls_back_to_the_payload_when_not_given(): void
    {
        $payload = QrisPayloadFactory::static('WARUNG SEDERHANA', 'BOGOR');

        $tenant = $this->action()->configure($this->owner, $payload);

        $this->assertSame('WARUNG SEDERHANA', $tenant->qris_merchant_name);
    }

    public function test_an_explicit_merchant_name_wins_over_the_payload(): void
    {
        $tenant = $this->action()->configure($this->owner, QrisPayloadFactory::static('NAMA DI QRIS'), 'Nama Tampilan Toko');

        $this->assertSame('Nama Tampilan Toko', $tenant->qris_merchant_name);
    }

    public function test_payload_is_normalised_before_storage(): void
    {
        $payload = QrisPayloadFactory::static();
        $mangled = '  '.substr($payload, 0, 30)."\n".substr($payload, 30).'  ';

        $tenant = $this->action()->configure($this->owner, $mangled);

        $this->assertSame($payload, $tenant->qris_payload);
    }

    public function test_an_invalid_payload_is_rejected_and_changes_nothing(): void
    {
        $this->expectException(InvalidQrisPayload::class);

        try {
            $this->action()->configure($this->owner, 'bukan-qris-sama-sekali');
        } finally {
            $this->assertNull($this->tenant->refresh()->qris_payload);
            $this->assertSame(0, AuditLog::query()->count());
        }
    }

    public function test_a_dynamic_payload_is_rejected(): void
    {
        $this->expectException(InvalidQrisPayload::class);

        $this->action()->configure($this->owner, QrisPayloadFactory::dynamic());
    }

    public function test_an_actor_without_the_permission_is_rejected(): void
    {
        $this->expectException(ActionForbidden::class);

        try {
            $this->action()->configure($this->cashier, QrisPayloadFactory::static());
        } finally {
            $this->assertNull($this->tenant->refresh()->qris_payload);
            $this->assertSame(0, AuditLog::query()->count());
        }
    }

    public function test_configuring_records_an_audit_entry_without_the_raw_payload(): void
    {
        $payload = QrisPayloadFactory::static('TOKO AUDIT', 'SEMARANG');

        $this->action()->configure($this->owner, $payload, reason: 'Pasang QRIS baru');

        $log = AuditLog::query()->where('action', AuditAction::PaymentGatewayConfigured->value)->sole();

        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame('Pasang QRIS baru', $log->reason);
        $this->assertSame('qris_static', $log->metadata['channel']);
        $this->assertSame('TOKO AUDIT', $log->metadata['merchant_name']);
        $this->assertSame(hash('sha256', $payload), $log->metadata['fingerprint']);
        $this->assertArrayNotHasKey('payload', $log->metadata);

        foreach ($log->metadata as $value) {
            $this->assertNotSame($payload, $value);
        }
    }

    public function test_clearing_removes_the_configuration_and_is_audited(): void
    {
        $this->action()->configure($this->owner, QrisPayloadFactory::static());

        $tenant = $this->action()->clear($this->owner, 'Ganti ke QRIS bank lain');

        $this->assertNull($tenant->qris_payload);
        $this->assertNull($tenant->qris_merchant_name);
        $this->assertFalse($tenant->hasQrisConfigured());

        $log = AuditLog::query()
            ->where('action', AuditAction::PaymentGatewayConfigured->value)
            ->get()
            ->first(fn (AuditLog $entry) => ($entry->metadata['cleared'] ?? false) === true);

        $this->assertNotNull($log);
        $this->assertTrue($log->metadata['cleared']);
    }

    public function test_a_missing_tenant_is_reported(): void
    {
        $this->expectException(TenantNotFound::class);

        (new ConfigureQris('00000000-0000-0000-0000-000000000000'))
            ->configure($this->owner, QrisPayloadFactory::static());
    }

    public function test_qris_is_tenant_scoped(): void
    {
        $other = Tenant::factory()->create();

        $this->action()->configure($this->owner, QrisPayloadFactory::static('TOKO SATU'));

        $this->assertNull($other->refresh()->qris_payload);
        $this->assertFalse($other->hasQrisConfigured());
    }

    public function test_merchant_display_name_falls_back_to_the_tenant_name(): void
    {
        $this->assertSame($this->tenant->name, $this->tenant->qrisMerchantDisplayName());
    }
}
