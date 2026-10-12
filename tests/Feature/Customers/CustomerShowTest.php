<?php

namespace Tests\Feature\Customers;

use App\Enums\FeatureProfile;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CustomerShowTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_show_page_displays_receivables(): void
    {
        $tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::CreditSales->value,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('pemilik');

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $transaction = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-001',
        ]);

        $receivable = Receivable::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $transaction->id,
            'customer_id' => $customer->id,
            'original_amount_minor' => 50000,
            'remaining_minor' => 30000,
            'status' => 'open',
            'due_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}/show");

        $response->assertStatus(200);
        $response->assertSee('INV-TEST-001');
        $response->assertSee('30.000'); // remaining amount
    }

    public function test_customer_show_page_shows_payment_history(): void
    {
        $tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::CreditSales->value,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('pemilik');

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $transaction = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-002',
        ]);

        $receivable = Receivable::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $transaction->id,
            'customer_id' => $customer->id,
            'original_amount_minor' => 50000,
            'remaining_minor' => 0,
            'status' => 'closed',
            'due_at' => now()->addDays(7),
        ]);

        $payment = ReceivablePayment::create([
            'tenant_id' => $tenant->id,
            'receivable_id' => $receivable->id,
            'amount_minor' => 20000,
            'payment_method' => 'cash',
            'recorded_by' => $user->id,
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}/show");

        $response->assertStatus(200);
        $response->assertSee('INV-TEST-002');
        $response->assertSee('20.000'); // payment amount
    }
}
