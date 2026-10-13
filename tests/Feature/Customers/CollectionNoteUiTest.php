<?php

namespace Tests\Feature\Customers;

use App\Actions\Receivables\AddReceivableNote;
use App\Enums\CollectionChannel;
use App\Enums\FeatureProfile;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivableNote;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Increment 51 — catatan penagihan lewat UI detail pelanggan (FR-07).
 *
 * Catatan: isi halaman detail pelanggan hidup di fragmen Volt anak
 * (`volt-anonymous-fragment-…`), sehingga `html()` setelah aksi tidak memuat
 * body halaman. Karena itu pemeriksaan render dilakukan pada mount, sedangkan
 * perilaku aksi diperiksa lewat state + basis data.
 */
class CollectionNoteUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Customer $customer;

    private Receivable $receivable;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenant = Tenant::factory()->create([
            'feature_profile' => FeatureProfile::CreditSales->value,
        ]);
        app(TenantContext::class)->set($this->tenant);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email_verified_at' => now(),
        ]);
        $this->owner->assignRole('owner');

        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-NOTE-001',
        ]);

        $this->receivable = Receivable::create([
            'tenant_id' => $this->tenant->id,
            'transaction_id' => $transaction->id,
            'customer_id' => $this->customer->id,
            'original_amount_minor' => 40000,
            'remaining_minor' => 40000,
            'status' => 'open',
        ]);
    }

    public function test_the_page_lists_existing_collection_notes(): void
    {
        $this->actingAs($this->owner);

        app(AddReceivableNote::class)->handle([
            'receivable_id' => $this->receivable->id,
            'note' => 'Sudah ditagih sekali, belum ada kepastian.',
            'channel' => CollectionChannel::Phone->value,
            'user_id' => $this->owner->id,
        ]);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->assertSee('Collection Notes')
            ->assertSee('Sudah ditagih sekali, belum ada kepastian.')
            ->assertSee('INV-NOTE-001')
            ->assertSee('Telepon');
    }

    public function test_owner_records_a_collection_note_through_the_page(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openNoteModal', $this->receivable->id)
            ->assertSet('showNoteModal', true)
            ->assertSet('noteReceivableId', $this->receivable->id)
            ->set('noteText', 'Dihubungi via WhatsApp, janji bayar Jumat.')
            ->set('noteChannel', CollectionChannel::Whatsapp->value)
            ->set('noteContactedAt', '2026-10-05')
            ->call('saveNote')
            ->assertSet('showNoteModal', false)
            ->assertHasNoErrors();

        $this->assertNull($component->get('noteText'));

        $note = ReceivableNote::sole();

        $this->assertSame($this->receivable->id, $note->receivable_id);
        $this->assertSame(CollectionChannel::Whatsapp->value, $note->channel);
        $this->assertSame('2026-10-05', $note->contacted_at->format('Y-m-d'));
        $this->assertSame($this->owner->id, $note->created_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'receivable.note_added',
            'entity_id' => $note->uuid,
        ]);

        // Mount ulang: catatan yang tersimpan ikut tampil di daftar.
        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->assertSee('Dihubungi via WhatsApp, janji bayar Jumat.')
            ->assertSee('WhatsApp');
    }

    public function test_a_blank_note_is_rejected_by_validation(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openNoteModal', $this->receivable->id)
            ->set('noteText', '')
            ->call('saveNote')
            ->assertHasErrors('noteText');

        $this->assertSame(0, ReceivableNote::count());
    }

    public function test_a_note_cannot_target_another_customers_receivable(): void
    {
        $foreignCustomer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $foreignTransaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $foreignCustomer->id,
        ]);
        $foreignReceivable = Receivable::create([
            'tenant_id' => $this->tenant->id,
            'transaction_id' => $foreignTransaction->id,
            'customer_id' => $foreignCustomer->id,
            'original_amount_minor' => 10000,
            'remaining_minor' => 10000,
            'status' => 'open',
        ]);

        $this->actingAs($this->owner);

        Livewire::test('customers.[Customer].show', ['customer' => $this->customer])
            ->call('openNoteModal', $foreignReceivable->id)
            ->assertStatus(404);

        $this->assertSame(0, ReceivableNote::count());
    }
}
