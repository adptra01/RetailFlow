<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan transaksi terkontrol / void (§17.4 "Retur dan void", MVP §5.1 #10).
 *
 * §17.4 menetapkan: "Transaksi selesai tidak dihapus permanen", pembatalan
 * memerlukan alasan dan izin, membuat record koreksi + mutasi balik, dan
 * operasinya idempoten. Karena itu pembatalan TIDAK menghapus baris — ia
 * menandai transaksi dan menyimpan jejaknya di sini:
 *
 *  - `payment_status = 'void'` (nilai yang sudah dicadangkan laporan);
 *  - `voided_at` / `voided_by` / `void_reason` — aktor, waktu, alasan (FR-12);
 *  - `stock_restored` — dokumen keputusan kebijakan: apakah stok dikembalikan
 *    (§17.4 "Pengembalian stok hanya dilakukan sesuai kebijakan retur").
 *
 * Kolom aditif & nullable sehingga transaksi lama (dan data impor) tetap sah.
 * Indeks `payment_status` ditambahkan karena laporan harus menyaring transaksi
 * batal pada setiap kueri periode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('payment_verification');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
            $table->boolean('stock_restored')->default(false)->after('void_reason');

            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason', 'stock_restored']);
        });
    }
};
