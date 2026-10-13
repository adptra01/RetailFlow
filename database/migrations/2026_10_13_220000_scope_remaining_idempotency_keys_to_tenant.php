<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sisa kunci idempotensi menjadi milik tenant, bukan global (§12).
 *
 * `receivable_payments.client_request_id` dan `sales_returns.client_request_id`
 * awalnya unik secara GLOBAL. Kunci itu dibuat perangkat, sehingga dua tenant
 * berbeda bisa kebetulan memakai kunci yang sama; dengan indeks global,
 * operasi tenant kedua gagal (atau — lebih buruk — pencarian idempotensi tanpa
 * batas tenant mengembalikan record tenant pertama).
 *
 * Migrasi ini hanya menyentuh INDEKS: tidak ada kolom yang dihapus dan tidak
 * ada baris data yang diubah. Kesatuan kunci ditegakkan per tenant
 * (`tenant_id` + `client_request_id`) sesuai batas isolasi data §12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropUnique(['client_request_id']);
            $table->unique(
                ['tenant_id', 'client_request_id'],
                'receivable_payments_tenant_client_request_unique',
            );
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropUnique(['client_request_id']);
            $table->unique(
                ['tenant_id', 'client_request_id'],
                'sales_returns_tenant_client_request_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropUnique('receivable_payments_tenant_client_request_unique');
            $table->unique(['client_request_id']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropUnique('sales_returns_tenant_client_request_unique');
            $table->unique(['client_request_id']);
        });
    }
};
