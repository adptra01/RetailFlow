<?php

namespace Tests\Feature\Receivables;

use App\Actions\Receivables\RecordReceivablePayment;
use App\Actions\Receivables\ReverseReceivablePayment;
use App\Actions\Sales\CreateSale;
use App\Actions\Sales\VoidSale;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Receivables\PaymentReversalNotAllowed;
use App\Exceptions\Sales\VoidNotAllowed;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Koreksi/pembatalan pembayaran piutang — prosedur yang diaudit (FR-07).
 *
 * Yang diuji adalah jaminan domain: append-only (tidak menghapus), alasan &
 * izin wajib, saldo dipulihkan, idempoten, jejak audit, isolasi tenant, dan
 * integrasi dengan void transaksi.
 */
class ReverseReceivablePaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('owner');

        $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->viewer->assignRole('viewer');
    }

    /**
     * @return array{0: Transaction, 1: Customer, 2: Product}
     */
    private function creditSale(int $totalMinor = 15000, int $paidMinor = 10000): array
    {
        $product = Product::factory()->create([
            'stock' => 10,
            'price' => $totalMinor,
            'is_unlimited_stock' => false,
        ]);

        $customer = Customer::create(['name' => 'Rina Marlina']);

        $sale = app(CreateSale::class)->handle([
            'user_id' => $this->owner->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'payment_method' => 'cash',
            'paid_minor' => $paidMinor,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price_minor' => $totalMinor],
            ],
        ]);

        return [$sale, $customer, $product];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pay(int $receivableId, array $overrides = []): ReceivablePayment
    {
        return app(RecordReceivablePayment::class)->handle(array_merge([
            'receivable_id' => $receivableId,
            'amount_minor' => 1000,
            'user_id' => $this->owner->id,
        ], $overrides));
    }

    public function test_reversal_restores_remaining_balance_and_reopens_receivable(): void
    {
        [$sale, $customer] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $payment = $this->pay($receivable->id, ['amount_minor' => 5000]);
        $this->assertSame('closed', $receivable->refresh()->status);
        $this->assertSame(0.0, (float) $customer->refresh()->balance);

        $reversed = app(ReverseReceivablePayment::class)->handle([
            'payment_id' => $payment->id,
            'user_id' => $this->owner->id,
            'reason' => 'Pembayaran tercatat ganda',
        ]);

        $this->assertTrue($reversed->isReversed());
        $this->assertSame('open', $receivable->refresh()->status);
        $this->assertSame(5000, $receivable->remaining_minor);
        $this->assertSame(5000.0, (float) $customer->refresh()->balance);
    }

    public function test_reversal_is_append_only_and_writes_audit(): void
    {
        [$sale] = $this->creditSale();
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $payment = $this->pay($receivable->id, ['amount_minor' => 2000]);

        app(ReverseReceivablePayment::class)->handle([
            'payment_id' => $payment->id,
            'user_id' => $this->owner->id,
            'reason' => 'Salah pelanggan',
        ]);

        // Baris tidak dihapus — hanya ditandai.
        $this->assertSame(1, ReceivablePayment::count());
        $payment->refresh();
        $this->assertNotNull($payment->reversed_at);
        $this->assertSame('Salah pelanggan', $payment->reversal_reason);
        $this->assertSame($this->owner->id, $payment->reversed_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ReceivablePaymentCorrected->value,
            'entity_type' => 'receivable_payment',
            'entity_id' => $payment->uuid,
            'actor_id' => $this->owner->id,
            'reason' => 'Salah pelanggan',
        ]);
    }

    public function test_reversal_is_idempotent(): void
    {
        [$sale, $customer] = $this->creditSale();
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $payment = $this->pay($receivable->id, ['amount_minor' => 3000]);
        $afterFirstPay = $receivable->refresh()->remaining_minor;

        $action = app(ReverseReceivablePayment::class);
        $first = $action->handle([
            'payment_id' => $payment->id,
            'user_id' => $this->owner->id,
            'reason' => 'Koreksi pertama',
        ]);
        $second = $action->handle([
            'payment_id' => $payment->id,
            'user_id' => $this->owner->id,
            'reason' => 'Koreksi ulang',
        ]);

        $this->assertSame($first->id, $second->id);
        // Saldo dipulihkan tepat satu kali.
        $this->assertSame($afterFirstPay + 3000, $receivable->refresh()->remaining_minor);
        $this->assertSame(1, AuditLog::where('action', AuditAction::ReceivablePaymentCorrected->value)->count());
        $this->assertSame('Koreksi pertama', $payment->refresh()->reversal_reason);
    }

    public function test_reversal_requires_a_reason(): void
    {
        [$sale] = $this->creditSale();
        $receivable = Receivable::where('transaction_id', $sale->id)->first();
        $payment = $this->pay($receivable->id, ['amount_minor' => 1000]);

        $this->expectException(PaymentReversalNotAllowed::class);

        app(ReverseReceivablePayment::class)->handle([
            'payment_id' => $payment->id,
            'user_id' => $this->owner->id,
            'reason' => '   ',
        ]);
    }

    public function test_reversal_of_unknown_payment_is_rejected(): void
    {
        $this->expectException(PaymentReversalNotAllowed::class);

        app(ReverseReceivablePayment::class)->handle([
            'payment_id' => 999999,
            'user_id' => $this->owner->id,
            'reason' => 'Tidak ada',
        ]);
    }

    public function test_reversal_requires_permission_when_actor_given(): void
    {
        [$sale] = $this->creditSale();
        $receivable = Receivable::where('transaction_id', $sale->id)->first();
        $payment = $this->pay($receivable->id, ['amount_minor' => 1000]);

        $this->assertFalse($this->viewer->can(Permission::CorrectReceivablePayment->value));

        $this->expectException(ActionForbidden::class);

        app(ReverseReceivablePayment::class)->handle([
            'payment_id' => $payment->id,
            'user_id' => $this->viewer->id,
            'reason' => 'Coba koreksi',
        ], $this->viewer);
    }

    public function test_owner_with_permission_can_reverse(): void
    {
        [$sale] = $this->creditSale();
        $receivable = Receivable::where('transaction_id', $sale->id)->first();
        $payment = $this->pay($receivable->id, ['amount_minor' => 1000]);

        $this->assertTrue($this->owner->can(Permission::CorrectReceivablePayment->value));

        $reversed = app(ReverseReceivablePayment::class)->handle([
            'payment_id' => $payment->id,
            'user_id' => $this->owner->id,
            'reason' => 'Disetujui pemilik',
        ], $this->owner);

        $this->assertTrue($reversed->isReversed());
    }

    public function test_reversal_respects_tenant_boundary(): void
    {
        $otherTenant = Tenant::factory()->create();

        app(TenantContext::class)->set($otherTenant);

        $otherCustomer = Customer::create(['name' => 'Pelanggan Lain']);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'stock' => 5,
            'price' => 10000,
        ]);

        $foreignSale = app(CreateSale::class)->handle([
            'user_id' => $this->owner->id,
            'customer_id' => $otherCustomer->id,
            'customer_name' => $otherCustomer->name,
            'payment_method' => 'cash',
            'paid_minor' => 0,
            'tenant_id' => $otherTenant->id,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $otherProduct->id, 'quantity' => 1, 'unit_price_minor' => 10000],
            ],
        ]);

        $foreignReceivable = Receivable::where('transaction_id', $foreignSale->id)->first();
        $foreignPayment = $this->pay($foreignReceivable->id, ['amount_minor' => 1000]);

        app(TenantContext::class)->set($this->tenant);

        // Mencoba mengoreksi pembayaran tenant lain dengan tenant_id kita.
        $this->expectException(PaymentReversalNotAllowed::class);

        app(ReverseReceivablePayment::class)->handle([
            'payment_id' => $foreignPayment->id,
            'user_id' => $this->owner->id,
            'reason' => 'Tidak boleh',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_void_is_allowed_after_receivable_payments_are_reversed(): void
    {
        [$sale] = $this->creditSale(totalMinor: 15000, paidMinor: 10000);
        $receivable = Receivable::where('transaction_id', $sale->id)->first();

        $payment = $this->pay($receivable->id, ['amount_minor' => 5000]);

        // Tanpa koreksi, void ditolak karena ada pembayaran piutang.
        try {
            app(VoidSale::class)->handle([
                'transaction_id' => $sale->id,
                'user_id' => $this->owner->id,
                'reason' => 'Salah input',
            ], $this->owner);
            $this->fail('VoidSale seharusnya ditolak saat ada pembayaran aktif.');
        } catch (VoidNotAllowed $e) {
            // diharapkan
        }

        // Setelah dikoreksi, void boleh berjalan.
        app(ReverseReceivablePayment::class)->handle([
            'payment_id' => $payment->id,
            'user_id' => $this->owner->id,
            'reason' => 'Kembalikan ke pelanggan',
        ], $this->owner);

        $result = app(VoidSale::class)->handle([
            'transaction_id' => $sale->id,
            'user_id' => $this->owner->id,
            'reason' => 'Salah input',
        ], $this->owner);

        $this->assertFalse($result['already_void']);
        $this->assertSame('void', $sale->refresh()->payment_status);
        $this->assertSame('void', $receivable->refresh()->status);
    }
}
