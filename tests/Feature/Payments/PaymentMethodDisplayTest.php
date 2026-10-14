<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Tampilan metode & status verifikasi pembayaran (FR-05).
 *
 * Latar belakang: halaman transaksi lama menyimpan peta label di skrip
 * halaman sebagai array biasa. Volt hanya mengompilasi closure/`state()`,
 * sehingga array itu hilang dan tampilan diam-diam jatuh ke nilai mentah
 * (`cash`) dengan lencana abu-abu. Tes ini mengunci perbaikannya sekaligus
 * memastikan QRIS statis tampil berlabel dan ditandai belum terverifikasi.
 */
class PaymentMethodDisplayTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->user = User::factory()->create();
    }

    private function transaction(array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'user_id' => $this->user->id,
            'customer_name' => 'Umum',
            'subtotal_minor' => 25000,
            'paid_minor' => 25000,
            'change_minor' => 0,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_status' => 'paid',
            'payment_verification' => PaymentVerification::CashierRecorded->value,
        ], $attributes));
    }

    public function test_the_index_shows_the_indonesian_label_for_a_known_method(): void
    {
        $this->transaction(['invoice_number' => 'INV-DISPLAY-001']);

        $this->actingAs($this->user)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('INV-DISPLAY-001')
            ->assertSee('Tunai');
    }

    public function test_the_index_labels_qris_static(): void
    {
        $this->transaction([
            'invoice_number' => 'INV-DISPLAY-QRIS',
            'payment_method' => PaymentMethod::QrisStatic->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('QRIS (statis)');
    }

    public function test_the_index_tolerates_a_method_outside_the_catalogue(): void
    {
        $this->transaction([
            'invoice_number' => 'INV-DISPLAY-LEGACY',
            'payment_method' => 'voucher',
        ]);

        $this->actingAs($this->user)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('INV-DISPLAY-LEGACY')
            ->assertSee('voucher');
    }

    public function test_the_dashboard_labels_the_payment_method(): void
    {
        $this->transaction([
            'invoice_number' => 'INV-DASH-QRIS',
            'payment_method' => PaymentMethod::QrisStatic->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('QRIS (statis)');
    }

    public function test_the_edit_page_offers_qris_static_and_shows_the_verification_badge(): void
    {
        $transaction = $this->transaction([
            'invoice_number' => 'INV-EDIT-QRIS',
            'payment_method' => PaymentMethod::QrisStatic->value,
            'payment_verification' => PaymentVerification::CashierRecorded->value,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('transactions.edit', ['transaction' => $transaction]))
            ->assertOk();

        // Opsi QRIS tersedia dan labelnya terbaca.
        $response->assertSee('QRIS (statis)');
        // Status verifikasi ditampilkan apa adanya (belum terverifikasi).
        $response->assertSee('Dicatat kasir');
    }

    public function test_the_edit_page_shows_a_verified_badge_distinct_from_cashier_recorded(): void
    {
        $transaction = $this->transaction([
            'invoice_number' => 'INV-EDIT-VERIFIED',
            'payment_method' => PaymentMethod::Transfer->value,
            'payment_verification' => PaymentVerification::Verified->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('transactions.edit', ['transaction' => $transaction]))
            ->assertOk()
            ->assertSee('Terverifikasi')
            ->assertDontSee('Dicatat kasir');
    }

    public function test_the_receipt_labels_qris_static_and_marks_it_unverified(): void
    {
        Setting::create([
            'store_name' => 'Toko Uji',
            'store_address' => 'Jl. Uji 1',
            'store_phone' => '0812',
        ]);

        $transaction = $this->transaction([
            'invoice_number' => 'INV-RCPT-QRIS',
            'payment_method' => PaymentMethod::QrisStatic->value,
        ]);

        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'unit_price_minor' => 25000,
            'subtotal_minor' => 25000,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/transactions/'.$transaction->id.'/receipt')
            ->assertOk();

        $response->assertSee('QRIS (statis)');
        $response->assertSee('belum terverifikasi');
    }

    public function test_the_receipt_omits_the_unverified_note_for_cash(): void
    {
        Setting::create([
            'store_name' => 'Toko Uji',
            'store_address' => 'Jl. Uji 1',
            'store_phone' => '0812',
        ]);

        $transaction = $this->transaction([
            'invoice_number' => 'INV-RCPT-CASH',
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'unit_price_minor' => 25000,
            'subtotal_minor' => 25000,
        ]);

        $this->actingAs($this->user)
            ->get('/transactions/'.$transaction->id.'/receipt')
            ->assertOk()
            ->assertSee('Tunai')
            ->assertDontSee('belum terverifikasi');
    }
}
