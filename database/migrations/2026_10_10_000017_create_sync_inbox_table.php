<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 item 3 — API push/pull. Sisi server dari outbox: menampung operasi
 * yang dikirim perangkat secara durable dan idempoten (§11.3 langkah 6:
 * "Server menyimpan operasi yang sah dalam transaksi"; §11.1: "Sinkronisasi
 * harus aman terhadap pengiriman ulang").
 *
 * `id` (bigint naik) berperan sebagai urutan/penanda server yang monoton —
 * dasar kursor sinkronisasi pada item Sprint 4 berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_inbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 60);
            $table->string('entity_id', 64);
            $table->string('operation_type', 30);
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('local_sequence');
            $table->string('status', 20)->default('received');
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
            $table->index(['device_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_inbox');
    }
};
