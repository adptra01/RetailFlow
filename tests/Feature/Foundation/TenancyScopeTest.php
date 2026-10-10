<?php

namespace Tests\Feature\Foundation;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenancyScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_records_automatically_get_a_uuid_identity(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue(Str::isUuid($product->uuid));
        $this->assertSame(1, $product->version);
    }

    public function test_queries_are_scoped_to_the_bound_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Product::factory()->for($tenantA)->count(3)->create();
        Product::factory()->for($tenantB)->create();

        app(TenantContext::class)->set($tenantA);

        $this->assertSame(3, Product::count());
    }

    public function test_new_records_receive_the_bound_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantContext::class)->set($tenant);

        $product = Product::factory()->create();

        $this->assertSame($tenant->id, $product->tenant_id);
        $this->assertTrue($product->tenant->is($tenant));
    }

    public function test_tenant_context_is_inactive_without_binding(): void
    {
        $product = Product::factory()->create();

        $this->assertNull($product->tenant_id);
    }

    public function test_core_entities_soft_delete_instead_of_destroy(): void
    {
        $product = Product::factory()->create();
        $transaction = Transaction::factory()->create();

        $product->delete();
        $transaction->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertSoftDeleted('transactions', ['id' => $transaction->id]);
        $this->assertNull(Product::find($product->id));
        $this->assertNotNull(Product::withTrashed()->find($product->id));
    }
}
