<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
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

    public function test_can_search_transactions_by_invoice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Transaction::factory()->create([
            'customer_name' => $user->name,
            'invoice_number' => 'INV-001',
        ]);
        Transaction::factory()->create([
            'customer_name' => $user->name,
            'invoice_number' => 'INV-002',
        ]);

        $response = $this->get(route('transactions.index', ['search' => 'INV-001']));
        $response->assertSee('INV-001');
        $response->assertDontSee('INV-002');
    }
}
