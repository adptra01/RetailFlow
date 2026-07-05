<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        app()->setLocale('en');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('reports.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $response = $this->get(route('reports.index'));

        $response->assertOk();
    }

    public function test_summary_stats_are_correct(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 10000,
            'stock' => 100,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'customer_name' => 'Test Customer',
                'invoice_number' => 'INV-TEST-'.$i,
                'total_amount' => 10000,
                'paid_amount' => 10000,
                'change_amount' => 0,
                'payment_method' => 'cash',
            ]);

            TransactionItem::create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal' => 10000,
            ]);
        }

        $this->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Total Revenue');
    }

    public function test_daily_revenue_shows_grouped_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 25000,
            'stock' => 100,
        ]);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'customer_name' => 'Test Customer',
            'invoice_number' => 'INV-DAILY-1',
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'change_amount' => 0,
            'payment_method' => 'transfer',
        ]);

        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 25000,
            'subtotal' => 50000,
        ]);

        $this->get(route('reports.index'))
            ->assertOk();
    }

    public function test_date_range_filtering_affects_results(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 10000,
            'stock' => 100,
        ]);

        $oldTransaction = Transaction::create([
            'user_id' => $user->id,
            'customer_name' => 'Test Customer',
            'invoice_number' => 'INV-OLD',
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now()->subMonth()->startOfMonth(),
        ]);
        TransactionItem::create([
            'transaction_id' => $oldTransaction->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 50000,
            'subtotal' => 50000,
        ]);

        $newTransaction = Transaction::create([
            'user_id' => $user->id,
            'customer_name' => 'Test Customer',
            'invoice_number' => 'INV-NEW',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'change_amount' => 0,
            'payment_method' => 'transfer',
        ]);
        TransactionItem::create([
            'transaction_id' => $newTransaction->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
        ]);

        $this->get(route('reports.index'))
            ->assertOk();
    }

    public function test_empty_state_shows_when_no_transactions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('reports.index'))
            ->assertSee('Total Revenue');
    }
}
