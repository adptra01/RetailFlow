<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci idempotensi penjualan menjadi milik tenant, bukan global (§12).
 *
 * Awalnya `transactions.client_request_id` unik secara GLOBAL. Karena kunci
 * itu dibuat perangkat, dua tenant berbeda bisa kebetulan memilih kunci yang
 * sama; dengan indeks global, penjualan tenant kedua akan ditolak (atau —
 * lebih buruk — pencarian idempotensi tanpa batas tenant mengembalikan
 * penjualan tenant pertama).
 *
 * Migrasi ini hanya menyentuh INDEKS: tidak ada kolom yang dihapus dan tidak
 * ada baris data yang diubah. Setelah ini, kesatuan kunci ditegakkan per
 * tenant (`tenant_id` + `client_request_id`), sesuai batas isolasi data §12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['client_request_id']);
            $table->unique(['tenant_id', 'client_request_id'], 'transactions_tenant_client_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_tenant_client_request_unique');
            $table->unique(['client_request_id']);
        });
    }
};
