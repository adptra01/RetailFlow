<?php

namespace Tests\Feature\Reports;

use App\Actions\Reports\GenerateReceivableReport;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GenerateReceivableReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_generate_receivable_report_for_open_receivables(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $transaction = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-001',
        ]);

        $receivable = Receivable::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $transaction->id,
            'original_amount_minor' => 50000,
            'remaining_minor' => 50000,
            'status' => 'open',
            'due_at' => now()->addDays(7),
        ]);

        $action = new GenerateReceivableReport;
        $results = $action->handle(['tenant_id' => $tenant->id]);

        $this->assertCount(1, $results);
        $this->assertEquals('INV-TEST-001', $results->first()['invoice_number']);
        $this->assertEquals(50000, $results->first()['amount_minor']);
    }

    public function test_closed_receivables_are_excluded(): void
    {
        $tenant = Tenant::factory()->create();
        $transaction = Transaction::factory()->create(['tenant_id' => $tenant->id]);

        Receivable::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => $transaction->id,
            'original_amount_minor' => 50000,
            'remaining_minor' => 0,
            'status' => 'closed',
        ]);

        $action = new GenerateReceivableReport;
        $results = $action->handle(['tenant_id' => $tenant->id]);

        $this->assertCount(0, $results);
    }
}
