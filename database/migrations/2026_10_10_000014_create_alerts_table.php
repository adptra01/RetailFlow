<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alert lokal (FR-10 / plan §17.2 `alerts`).
 *
 * Deduplication is app-level, NOT a unique constraint: an identical alert
 * may respawn after the previous one is read (recurring condition), so we
 * dedup only against unread alerts with the same key (see AlertGenerator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index(); // "jika lokal" (FR-10)
            $table->string('alert_type', 64);               // stock.low | product.incomplete_data | receivable.due
            $table->string('deduplication_key', 255)->nullable();
            $table->string('severity', 16)->default('warning'); // info | warning | critical
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'deduplication_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
