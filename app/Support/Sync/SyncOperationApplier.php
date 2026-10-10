<?php

namespace App\Support\Sync;

use App\Models\SyncInbox;

/**
 * Registri handler penerapan operasi sinkronisasi (§11.3 langkah 6).
 *
 * Handler dipilih berdasarkan `supports()`. Bila tidak ada yang cocok,
 * operasi dibiarkan `received` (tersimpan durable, menunggu handler) alih-alih
 * ditolak — data perangkat tidak pernah dibuang (§11.5).
 */
final class SyncOperationApplier
{
    /**
     * @param  list<SyncOperationHandler>  $handlers
     */
    public function __construct(private readonly array $handlers = []) {}

    public function apply(SyncInbox $operation): SyncApplicationResult
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($operation)) {
                return $handler->apply($operation);
            }
        }

        return SyncApplicationResult::deferred();
    }

    public function supports(SyncInbox $operation): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($operation)) {
                return true;
            }
        }

        return false;
    }
}
