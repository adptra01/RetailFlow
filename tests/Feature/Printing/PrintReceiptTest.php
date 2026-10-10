<?php

namespace Tests\Feature\Printing;

use App\Actions\Sales\CreateSale;
use App\Actions\Sales\PrintReceipt;
use App\Enums\AuditAction;
use App\Exceptions\Printing\PrinterException;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Printing\NullReceiptPrinter;
use App\Printing\ReceiptPrinter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrintReceiptTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_null_printer_is_the_default_and_always_succeeds(): void
    {
        $this->assertInstanceOf(NullReceiptPrinter::class, app(ReceiptPrinter::class));

        $this->bootTenant();
        $sale = $this->checkout();

        $result = app(PrintReceipt::class)->handle($sale);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertSame(0, $this->failureAuditCount());
    }

    public function test_successful_print_invokes_printer_and_writes_no_failure_audit(): void
    {
        $this->bootTenant();
        $user = User::factory()->create();
        $sale = $this->checkout($user);

        $printer = new RecordingReceiptPrinter;
        $result = (new PrintReceipt($printer))->handle($sale, $user);

        $this->assertTrue($result->success);
        $this->assertSame([$sale->id], $printer->printed);
        $this->assertSame(0, $this->failureAuditCount());
    }

    public function test_failed_print_returns_failure_result_and_audits_actor(): void
    {
        $this->bootTenant();
        $user = User::factory()->create();
        $sale = $this->checkout($user);

        $result = (new PrintReceipt(new FailingReceiptPrinter))
            ->handle($sale, $user);

        $this->assertFalse($result->success);
        $this->assertSame('Printer tidak terhubung', $result->error);
        $this->assertSame($sale->id, $result->transaction->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ReceiptPrintFailed->value,
            'entity_type' => 'sale',
            'entity_id' => $sale->uuid,
            'actor_id' => $user->id,
            'reason' => 'Printer tidak terhubung',
        ]);
    }

    public function test_checkout_survives_printer_failure(): void
    {
        $this->bootTenant();
        $user = User::factory()->create();
        $product = $this->product(stock: 10, price: 15000);

        // 1. Checkout berhasil & committed.
        $sale = app(CreateSale::class)->handle($this->payload($user, $product));
        $this->assertSame(8, $product->refresh()->stock);

        // 2. Printer gagal SETELAH commit — tidak boleh me-rollback apa pun.
        $result = (new PrintReceipt(new FailingReceiptPrinter))->handle($sale, $user);

        $this->assertFalse($result->success);

        $fresh = Transaction::query()->findOrFail($sale->id);
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame(30000, $fresh->total_minor);
        $this->assertSame(1, $fresh->items()->count());
        $this->assertSame(8, $product->refresh()->stock);
        $this->assertDatabaseHas('transactions', ['id' => $sale->id]);
    }

    public function test_printer_is_never_required_to_store_a_sale(): void
    {
        $this->bootTenant();
        $user = User::factory()->create();
        $product = $this->product(stock: 5, price: 10000);

        // Tanpa menyentuh printer sama sekali, penjualan tetap tersimpan.
        $sale = app(CreateSale::class)->handle($this->payload($user, $product));

        $this->assertDatabaseHas('transactions', ['id' => $sale->id]);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(0, $this->failureAuditCount());
    }

    private function bootTenant(): Tenant
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);

        return $tenant;
    }

    private function product(int $stock, int $price): Product
    {
        return Product::factory()->create([
            'stock' => $stock,
            'price' => $price,
            'is_unlimited_stock' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, Product $product): array
    {
        return [
            'user_id' => $user->id,
            'payment_method' => 'cash',
            'paid_minor' => $product->price * 2,
            'client_request_id' => Str::uuid7()->toString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price_minor' => (int) $product->price],
            ],
        ];
    }

    private function checkout(?User $user = null): Transaction
    {
        $user ??= User::factory()->create();
        $product = $this->product(stock: 10, price: 15000);

        return app(CreateSale::class)->handle($this->payload($user, $product));
    }

    private function failureAuditCount(): int
    {
        return AuditLog::query()
            ->where('action', AuditAction::ReceiptPrintFailed->value)
            ->count();
    }
}

class RecordingReceiptPrinter implements ReceiptPrinter
{
    /** @var list<int> */
    public array $printed = [];

    public function print(Transaction $transaction): void
    {
        $this->printed[] = $transaction->id;
    }
}

class FailingReceiptPrinter implements ReceiptPrinter
{
    public function print(Transaction $transaction): void
    {
        throw new PrinterException('Printer tidak terhubung');
    }
}
