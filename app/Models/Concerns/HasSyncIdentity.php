<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Assigns a UUIDv7 sync identity to records that keep a local bigint key.
 *
 * The primary key stays a fast local bigint; `uuid` is the stable, portable
 * identifier shared across devices during synchronization (plan §17.1).
 */
trait HasSyncIdentity
{
    protected static function bootHasSyncIdentity(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid7();
            }
        });
    }
}
