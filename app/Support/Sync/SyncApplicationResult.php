<?php

namespace App\Support\Sync;

use App\Enums\SyncInboxStatus;

/**
 * Hasil penerapan satu operasi sinkronisasi terhadap domain (§11.3 langkah 6).
 */
final readonly class SyncApplicationResult
{
    private function __construct(
        public SyncInboxStatus $status,
        public ?string $entityId = null,
        public ?string $error = null,
    ) {}

    public static function applied(?string $entityId = null): self
    {
        return new self(SyncInboxStatus::Applied, $entityId);
    }

    public static function rejected(string $error): self
    {
        return new self(SyncInboxStatus::Rejected, null, $error);
    }

    /**
     * Konflik yang tidak dapat direkonsiliasi otomatis (§11.4, §11.5).
     */
    public static function conflict(string $error): self
    {
        return new self(SyncInboxStatus::Conflict, null, $error);
    }

    /**
     * Belum ada handler yang menerapkan tipe operasi ini; operasi tetap
     * tersimpan durable dan menunggu (status `received`).
     */
    public static function deferred(): self
    {
        return new self(SyncInboxStatus::Received);
    }
}
