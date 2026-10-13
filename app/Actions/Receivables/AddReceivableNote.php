<?php

namespace App\Actions\Receivables;

use App\Enums\AuditAction;
use App\Enums\CollectionChannel;
use App\Exceptions\Receivables\CollectionNoteNotAllowed;
use App\Models\AuditLog;
use App\Models\Receivable;
use App\Models\ReceivableNote;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Catatan penagihan piutang (FR-07: "Catatan penagihan").
 *
 * Satu-satunya jalur mencatat aktivitas penagihan. Menegakkan:
 *  - **catatan wajib**: catatan kosong ditolak;
 *  - **saluran valid**: `channel` divalidasi terhadap `CollectionChannel`;
 *  - **kepemilikan piutang**: piutang dicari dengan filter `tenant_id` eksplisit
 *    (`withoutGlobalScopes`) sehingga catatan tak bisa menempel ke piutang
 *    tenant lain;
 *  - **append-only**: tidak ada jalur mengubah/menghapus; perbaikan = catatan
 *    baru;
 *  - **jejak audit**: satu baris `receivable.note_added`.
 */
class AddReceivableNote
{
    /**
     * @param  array{
     *     receivable_id?: int,
     *     uuid?: string|null,
     *     note: string,
     *     channel?: string|null,
     *     contacted_at?: string|null,
     *     user_id?: int|null,
     *     tenant_id?: string|null,
     * }  $data
     */
    public function handle(array $data): ReceivableNote
    {
        $note = trim((string) ($data['note'] ?? ''));

        if ($note === '') {
            throw CollectionNoteNotAllowed::noteRequired();
        }

        $channel = $this->resolveChannel($data['channel'] ?? null);
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();
        $contactedAt = ! empty($data['contacted_at'])
            ? Carbon::parse((string) $data['contacted_at'])
            : Carbon::now();

        return DB::transaction(function () use ($data, $note, $channel, $tenantId, $contactedAt): ReceivableNote {
            $receivable = $this->findReceivable($data, $tenantId);

            $record = ReceivableNote::create([
                'tenant_id' => $tenantId,
                'receivable_id' => $receivable->id,
                'channel' => $channel,
                'note' => $note,
                'contacted_at' => $contactedAt,
                'created_by' => $data['user_id'] ?? null,
            ]);

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_id' => $data['user_id'] ?? null,
                'action' => AuditAction::ReceivableNoteAdded->value,
                'entity_type' => 'receivable_note',
                'entity_id' => $record->uuid,
                'metadata' => [
                    'receivable_id' => $receivable->uuid,
                    'transaction_id' => $receivable->transaction_id,
                    'channel' => $channel,
                    'contacted_at' => $contactedAt->toIso8601String(),
                ],
            ]);

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findReceivable(array $data, ?string $tenantId): Receivable
    {
        $query = Receivable::query()->withoutGlobalScopes();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if (isset($data['receivable_id'])) {
            $query->whereKey((int) $data['receivable_id']);
        } elseif (! empty($data['uuid'])) {
            $query->where('uuid', (string) $data['uuid']);
        }

        /** @var Receivable|null $receivable */
        $receivable = $query->first();

        if ($receivable === null) {
            throw CollectionNoteNotAllowed::receivableNotFound();
        }

        return $receivable;
    }

    private function resolveChannel(?string $channel): ?string
    {
        if ($channel === null || $channel === '') {
            return null;
        }

        if (CollectionChannel::tryFrom($channel) === null) {
            throw CollectionNoteNotAllowed::invalidChannel($channel);
        }

        return $channel;
    }
}
