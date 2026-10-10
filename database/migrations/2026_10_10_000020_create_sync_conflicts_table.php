<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 item 7 — Konflik stok (§11.4).
 *
 * Server mencatat konflik yang tidak dapat direkonsiliasi otomatis sebagai
 * KASUS yang perlu diselesaikan pengguna berwenang — bukan menghapus
 * transaksi perangkat diam-diam (§11.4, §11.5). Tidak ada tabel ini pada
 * §17.2 (yang hanya memuat `alerts` + `sync_outbox`), jadi ini perluasan yang
 * dibenarkan oleh §11.3 langkah 11 ("konflik dicatat dan ditampilkan kepada
 * pengguna yang berwenang") dan §11.4 ("konflik dibuat sebagai kasus").
 *
 * Idempotensi: satu kasus per `(operation_id, type)` — pengiriman ulang tidak
 * menggandakan kasus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->index();
            $table->uuid('device_id')->nullable()->index();
            $table->string('operation_id', 64)->nullable();
            $table->string('type', 32);                          // stock
            $table->string('status', 16)->default('open');       // open | resolved | dismissed
            $table->string('entity_type', 32)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->json('details')->nullable();
            $table->json('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['operation_id', 'type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
