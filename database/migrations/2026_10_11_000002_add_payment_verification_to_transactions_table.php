<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-05: bedakan status verifikasi pembayaran dari status penyelesaian
 * (`payment_status` = paid/credit).
 *
 * Default `cashier_recorded` — seluruh transaksi yang sudah ada memang
 * "dicatat kasir" dan tidak pernah diverifikasi gateway. Additive: satu kolom
 * baru dengan default, tidak ada backfill destruktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_verification', 30)
                ->default('cashier_recorded')
                ->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('payment_verification');
        });
    }
};
