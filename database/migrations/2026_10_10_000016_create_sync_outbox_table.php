<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 item 2 — Outbox sinkronisasi (§11.2, §17). Antrean persisten
 * operasi lokal yang harus dikirim ke server; ditulis dalam transaksi yang
 * sama dengan perubahan domain (transactional outbox, §11.1/§13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_outbox', function (Blueprint $table) {
            $table->uuid('operation_id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 60);
            $table->string('entity_id', 64);
            $table->string('operation_type', 30);
            $table->json('payload');
            // Urutan lokal per perangkat — menjaga urutan operasi (§11.3).
            $table->unsignedBigInteger('local_sequence');
            $table->string('sync_status', 20)->default('pending');
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'local_sequence']);
            $table->index(['tenant_id', 'sync_status']);
            $table->index(['device_id', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_outbox');
    }
};
