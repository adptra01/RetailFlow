<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TransactionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('transactions.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $response = $this->get(route('transactions.index'));

        $response->assertOk();
    }

    public function test_can_create_a_transaction_with_items(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 25000,
            'stock' => 50,
        ]);

        Livewire::test('transactions.create')
            ->call('addToCart', $product->id)
            ->set('paid_amount', 50000)
            ->call('confirmSave')
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('transactions', [
            'payment_method' => 'cash',
            'total_amount' => 25000,
            'paid_amount' => 50000,
            'change_amount' => 25000,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 25000,
            'subtotal' => 25000,
        ]);
    }

    public function test_cannot_create_transaction_without_items(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('transactions.create')
            ->set('paid_amount', 10000)
            ->call('confirmSave')
            ->assertOk();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cannot_create_transaction_with_insufficient_payment(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 50000,
            'stock' => 10,
        ]);

        Livewire::test('transactions.create')
            ->call('addToCart', $product->id)
            ->set('paid_amount', 10000)
            ->call('confirmSave')
            ->assertOk();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_can_add_multiple_items_to_cart(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product1 = Product::factory()->create(['category_id' => $category->id, 'price' => 10000, 'stock' => 10]);
        $product2 = Product::factory()->create(['category_id' => $category->id, 'price' => 20000, 'stock' => 10]);

        Livewire::test('transactions.create')
            ->call('addToCart', $product1->id)
            ->call('addToCart', $product2->id)
            ->set('paid_amount', 50000)
            ->call('confirmSave')
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('transactions', [
            'total_amount' => 30000,
            'paid_amount' => 50000,
            'change_amount' => 20000,
        ]);

        $this->assertDatabaseCount('transaction_items', 2);
    }

    public function test_can_update_cart_item_quantity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 10000, 'stock' => 10]);

        Livewire::test('transactions.create')
            ->call('addToCart', $product->id)
            ->call('incrementQty', 0)
            ->call('incrementQty', 0)
            ->set('paid_amount', 50000)
            ->call('confirmSave')
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('transactions', [
            'total_amount' => 30000,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'product_id' => $product->id,
            'quantity' => 3,
            'subtotal' => 30000,
        ]);
    }

    public function test_can_decrement_cart_item_quantity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 10000, 'stock' => 10]);

        Livewire::test('transactions.create')
            ->call('addToCart', $product->id)
            ->call('incrementQty', 0)
            ->call('incrementQty', 0)
            ->set('paid_amount', 50000)
            ->call('decrementQty', 0)
            ->call('confirmSave')
            ->call('save')
            ->assertOk();

        $this->assertDatabaseHas('transactions', [
            'total_amount' => 20000,
        ]);

        $this->assertDatabaseHas('transaction_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'subtotal' => 20000,
        ]);
    }

    public function test_can_remove_item_from_cart(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 10000, 'stock' => 10]);

        Livewire::test('transactions.create')
            ->call('addToCart', $product->id)
            ->call('removeFromCart', 0)
            ->assertSet('cart', []);
    }

    public function test_can_view_transaction_detail(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 15000, 'stock' => 10]);

        // Create a transaction first using the create component
        Livewire::test('transactions.create')
            ->call('addToCart', $product->id)
            ->set('paid_amount', 15000)
            ->call('confirmSave')
            ->call('save')
            ->assertOk();

        $transaction = Transaction::first();

        // View the detail on the index page
        Livewire::test('transactions.index')
            ->call('viewDetail', $transaction->id)
            ->assertSet('showDetailModal', true)
            ->assertSet('viewingTransactionId', $transaction->id);
    }

    public function test_can_delete_a_transaction(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 10000, 'stock' => 10]);

        Livewire::test('transactions.create')
            ->call('addToCart', $product->id)
            ->set('paid_amount', 10000)
            ->call('confirmSave')
            ->call('save')
            ->assertOk();

        $transaction = Transaction::first();

        Livewire::test('transactions.index')
            ->call('confirmDelete', $transaction->id)
            ->call('delete')
            ->assertOk();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseMissing('transaction_items', ['transaction_id' => $transaction->id]);
    }

    public function test_can_search_transactions_by_invoice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Transaction::factory()->create([
            'customer' => $user->name,
            'invoice_number' => 'INV-001',
        ]);
        Transaction::factory()->create([
            'customer' => $user->name,
            'invoice_number' => 'INV-002',
        ]);

        $response = $this->get(route('transactions.index', ['search' => 'INV-001']));
        $response->assertSee('INV-001');
        $response->assertDontSee('INV-002');
    }
}
