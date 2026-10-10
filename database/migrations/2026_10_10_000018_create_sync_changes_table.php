<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 item 4 — Cursor sinkronisasi. Jurnal perubahan sisi server:
 * setiap perubahan record bisnis dicatat dengan `sequence` monoton yang
 * berperan sebagai kursor (§11.3 langkah 9 "Perangkat menarik perubahan
 * server sejak cursor terakhir"; §12 "version atau cursor yang sesuai").
 *
 * Append-only. `sequence` adalah kunci primary bigint naik sehingga urutan
 * total dijamin tanpa bergantung pada resolusi `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id('sequence');
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 60);
            $table->string('entity_id', 64);
            $table->string('operation', 20);
            // Snapshot record (cast attributes, atribut tersembunyi dikecualikan).
            $table->json('payload')->nullable();
            // Perangkat asal perubahan; null = perubahan dari server/web.
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'sequence']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
    }
};
