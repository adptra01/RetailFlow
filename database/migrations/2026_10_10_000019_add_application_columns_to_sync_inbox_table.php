<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 item 6 — Idempotensi server. Menambah jejak penerapan domain pada
 * operasi yang diterima (§11.3 langkah 6–7): kapan diterapkan dan record
 * domain mana yang dihasilkan. `received` = tersimpan menunggu penerapan;
 * `applied`/`rejected`/`conflict` = hasil penerapan.
 *
 * Aditif; tidak mengubah kolom yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_inbox', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable()->after('received_at');
            $table->string('applied_entity_id', 64)->nullable()->after('applied_at');
        });
    }

    public function down(): void
    {
        Schema::table('sync_inbox', function (Blueprint $table) {
            $table->dropColumn(['applied_at', 'applied_entity_id']);
        });
    }
};
