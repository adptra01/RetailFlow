<?php

namespace Tests\Feature\Payments;

use App\Actions\Sales\CreateSale;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Enums\SyncOperationType;
use App\Exceptions\Payments\QrisNotConfigured;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Exceptions\Sales\InvalidPaymentVerification;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncOutbox;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\QrisPayloadFactory;
use Tests\TestCase;

/**
 * Pembayaran QRIS statis di titik penjualan (FR-05, irisan 2).
 *
 * Aturan yang dijaga di aksi domain:
 *  - `payment_method` divalidasi terhadap katalog (batas tulis) — nilai asing
 *    ditolak, bukan diam-diam menjadi tunai;
 *  - QRIS statis tidak pernah "terverifikasi" hanya karena kasir mencatatnya;
 *  - nominal QRIS harus tepat (tanpa kembalian, tanpa kredit);
 *  - QRIS tidak dapat dipakai bila tenant belum mengunggah QRIS;
 *  - status verifikasi ikut tersimpan, terekam audit, dan ikut ke outbox.
 */
class QrisCheckoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'qris_payload' => QrisPayloadFactory::static(),
            'qris_merchant_name' => 'Toko Uji',
        ]);

        app(TenantContext::class)->set($this->tenant);
    }

    private function product(int $stock = 10, int $price = 25000): Product
    {
        return Product::factory()->create([
            'stock' => $stock,
            'price' => $price,
            'is_unlimited_stock' => false,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $user, Product $product, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $user->id,
            'payment_method' => PaymentMethod::QrisStatic->value,
            'paid_minor' => 25000,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => 25000],
            ],
        ], $overrides);
    }

    public function test_qris_static_sale_is_recorded_as_cashier_recorded(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $sale = app(CreateSale::class)->handle($this->payload($user, $product));

        $this->assertSame(PaymentMethod::QrisStatic->value, $sale->payment_method);
        $this->assertSame(PaymentVerification::CashierRecorded->value, $sale->payment_verification);
        $this->assertFalse(PaymentVerification::from($sale->payment_verification)->isVerified());
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(0, $sale->change_minor);
        $this->assertSame(9, $product->refresh()->stock);
    }

    public function test_qris_static_sale_cannot_be_marked_verified(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $this->expectException(InvalidPaymentVerification::class);

        app(CreateSale::class)->handle($this->payload($user, $product, [
            'payment_verification' => PaymentVerification::Verified->value,
        ]));
    }

    public function test_manual_transfer_cannot_be_marked_verified_either(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $this->expectException(InvalidPaymentVerification::class);

        app(CreateSale::class)->handle($this->payload($user, $product, [
            'payment_method' => PaymentMethod::Transfer->value,
            'payment_verification' => PaymentVerification::Verified->value,
        ]));
    }

    public function test_cash_can_still_carry_a_non_verified_status(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $sale = app(CreateSale::class)->handle($this->payload($user, $product, [
            'payment_method' => PaymentMethod::Cash->value,
            'payment_verification' => PaymentVerification::AwaitingVerification->value,
        ]));

        $this->assertSame(PaymentVerification::AwaitingVerification->value, $sale->payment_verification);
    }

    public function test_qris_static_requires_the_exact_amount(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $this->expectException(InvalidPaymentAmount::class);

        app(CreateSale::class)->handle($this->payload($user, $product, ['paid_minor' => 20000]));
    }

    public function test_qris_static_rejects_change(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $this->expectException(InvalidPaymentAmount::class);

        app(CreateSale::class)->handle($this->payload($user, $product, ['paid_minor' => 30000]));
    }

    public function test_qris_static_is_rejected_when_the_tenant_has_no_qris(): void
    {
        $tenant = Tenant::factory()->create(['qris_payload' => null]);
        app(TenantContext::class)->set($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $product = $this->product();

        $this->expectException(QrisNotConfigured::class);

        app(CreateSale::class)->handle($this->payload($user, $product));
    }

    public function test_an_unknown_payment_method_is_rejected(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $this->expectException(\InvalidArgumentException::class);

        app(CreateSale::class)->handle($this->payload($user, $product, [
            'payment_method' => 'ewallet',
        ]));
    }

    public function test_qris_sale_records_verification_in_audit_metadata(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $sale = app(CreateSale::class)->handle($this->payload($user, $product));

        $audit = AuditLog::query()
            ->where('action', AuditAction::SaleCreated->value)
            ->where('entity_id', $sale->uuid)
            ->sole();

        $this->assertSame(PaymentMethod::QrisStatic->value, $audit->metadata['payment_method']);
        $this->assertSame(PaymentVerification::CashierRecorded->value, $audit->metadata['payment_verification']);
    }

    public function test_qris_sale_from_a_device_carries_verification_into_the_outbox(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $device = Device::factory()->create(['tenant_id' => $this->tenant->id]);

        $sale = app(CreateSale::class)->handle($this->payload($user, $product, [
            'device_id' => $device->id,
            'client_request_id' => 'req-qris-1',
        ]));

        $entry = SyncOutbox::query()
            ->where('entity_id', $sale->uuid)
            ->where('operation_type', SyncOperationType::Create->value)
            ->sole();

        $this->assertSame(PaymentMethod::QrisStatic->value, $entry->payload['payment_method']);
        $this->assertSame(PaymentVerification::CashierRecorded->value, $entry->payload['payment_verification']);
    }

    public function test_cash_sale_still_defaults_to_cashier_recorded(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $sale = app(CreateSale::class)->handle($this->payload($user, $product, [
            'payment_method' => PaymentMethod::Cash->value,
        ]));

        $this->assertSame(PaymentVerification::CashierRecorded->value, $sale->payment_verification);
    }

    public function test_the_sale_row_is_persisted_with_the_verification_column(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = $this->product();

        $sale = app(CreateSale::class)->handle($this->payload($user, $product));

        $fresh = Transaction::query()->withoutGlobalScopes()->findOrFail($sale->id);

        $this->assertSame(PaymentVerification::CashierRecorded->value, $fresh->payment_verification);
    }
}
