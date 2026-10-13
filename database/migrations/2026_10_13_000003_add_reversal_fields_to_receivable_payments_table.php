<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-07: koreksi/pembatalan pembayaran piutang lewat prosedur yang diaudit.
 *
 * Pembayaran piutang bersifat append-only secara domain; koreksi tidak
 * menghapus baris, melainkan menandai pembayaran sebagai dibalik (reversed)
 * beserta alasan, aktor, dan waktunya — dan mengembalikan saldo piutang lewat
 * aksi domain `ReverseReceivablePayment`. Jejaknya juga terekam di `audit_logs`.
 *
 * Aditif & non-destruktif: kolom baru semuanya nullable dengan default aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('paid_at');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_by');

            $table->index('reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropIndex(['reversed_at']);
            $table->dropColumn(['reversed_at', 'reversed_by', 'reversal_reason']);
        });
    }
};
