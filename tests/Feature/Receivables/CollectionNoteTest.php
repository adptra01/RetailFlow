<?php

namespace Tests\Feature\Receivables;

use App\Actions\Receivables\AddReceivableNote;
use App\Enums\AuditAction;
use App\Enums\CollectionChannel;
use App\Exceptions\Receivables\CollectionNoteNotAllowed;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivableNote;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Catatan penagihan piutang (FR-07: "Catatan penagihan").
 *
 * Jaminan domain: catatan wajib, saluran valid, piutang harus milik tenant yang
 * sama, append-only, dan setiap catatan meninggalkan jejak audit.
 */
class CollectionNoteTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Receivable $receivable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
        ]);

        $this->receivable = Receivable::create([
            'tenant_id' => $this->tenant->id,
            'transaction_id' => $transaction->id,
            'customer_id' => $customer->id,
            'original_amount_minor' => 50000,
            'remaining_minor' => 50000,
            'status' => 'open',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function add(array $overrides = []): ReceivableNote
    {
        return app(AddReceivableNote::class)->handle(array_merge([
            'receivable_id' => $this->receivable->id,
            'note' => 'Dihubungi via WhatsApp, janji bayar minggu depan.',
            'channel' => CollectionChannel::Whatsapp->value,
            'user_id' => $this->user->id,
        ], $overrides));
    }

    public function test_a_note_is_recorded_with_channel_author_and_audit(): void
    {
        $note = $this->add(['contacted_at' => '2026-10-01T09:30:00+07:00']);

        $this->assertSame($this->receivable->id, $note->receivable_id);
        $this->assertSame(CollectionChannel::Whatsapp->value, $note->channel);
        $this->assertSame($this->user->id, $note->created_by);
        $this->assertSame('2026-10-01', $note->contacted_at->format('Y-m-d'));
        $this->assertSame($this->tenant->id, $note->tenant_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ReceivableNoteAdded->value,
            'entity_type' => 'receivable_note',
            'entity_id' => $note->uuid,
            'actor_id' => $this->user->id,
        ]);
    }

    public function test_contacted_at_defaults_to_now(): void
    {
        $note = $this->add();

        $this->assertNotNull($note->contacted_at);
        $this->assertTrue($note->contacted_at->isToday());
    }

    public function test_channel_is_optional(): void
    {
        $note = $this->add(['channel' => null]);

        $this->assertNull($note->channel);
    }

    public function test_a_blank_note_is_rejected(): void
    {
        $this->expectException(CollectionNoteNotAllowed::class);

        $this->add(['note' => '   ']);
    }

    public function test_an_unknown_receivable_is_rejected(): void
    {
        $this->expectException(CollectionNoteNotAllowed::class);

        $this->add(['receivable_id' => 999999]);
    }

    public function test_an_unknown_channel_is_rejected(): void
    {
        $this->expectException(CollectionNoteNotAllowed::class);

        $this->add(['channel' => 'telegram']);
    }

    public function test_notes_are_append_only(): void
    {
        $this->add(['note' => 'Penagihan pertama.']);
        $this->add(['note' => 'Penagihan kedua.']);

        $this->assertSame(2, ReceivableNote::count());
        $this->assertSame(
            ['Penagihan pertama.', 'Penagihan kedua.'],
            ReceivableNote::orderBy('id')->pluck('note')->all(),
        );
    }

    public function test_a_note_cannot_be_attached_across_tenants(): void
    {
        $otherTenant = Tenant::factory()->create();

        $this->expectException(CollectionNoteNotAllowed::class);

        $this->add(['tenant_id' => $otherTenant->id]);
    }
}
