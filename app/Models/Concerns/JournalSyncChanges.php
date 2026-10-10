<?php

namespace App\Models\Concerns;

use App\Enums\SyncChangeOperation;
use App\Support\Sync\SyncChangeJournal;

/**
 * Mencatat perubahan model ke jurnal sinkronisasi server (§11.3 langkah 9).
 *
 * Tempelkan pada model bisnis yang disinkronkan. Setiap create/update/delete
 * menulis satu baris `sync_changes`; bila dipanggil di dalam transaksi domain,
 * baris jurnal ikut atomik dengan perubahan.
 *
 * Record tanpa tenant (seeder/CLI) dilewati oleh journal.
 */
trait JournalSyncChanges
{
    protected static function bootJournalSyncChanges(): void
    {
        static::created(function ($model): void {
            app(SyncChangeJournal::class)->record($model, SyncChangeOperation::Created);
        });

        static::updated(function ($model): void {
            app(SyncChangeJournal::class)->record($model, SyncChangeOperation::Updated);
        });

        static::deleted(function ($model): void {
            app(SyncChangeJournal::class)->record($model, SyncChangeOperation::Deleted);
        });
    }
}
