<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran campuran (FR-05 "Pembayaran campuran").
 *
 * Sebelum increment ini, satu penjualan hanya sanggup mewakili satu metode:
 * `transactions.payment_method` + `paid_minor`. Tabel ini memecah pembayaran
 * menjadi beberapa *tender* (satu baris = satu kali serah-bayar), sehingga
 * penjualan dapat dibayar tunai sebagian + non-tunai sebagian tanpa kehilangan
 * atribusi per metode.
 *
 * Aturan nominal dijaga di aksi domain `CreateSale`, bukan di skema:
 *  - jumlah `amount_minor` seluruh baris = `transactions.paid_minor`;
 *  - total tender non-tunai tidak boleh melebihi `transactions.total_minor`
 *    (kembalian hanya berasal dari tunai);
 *  - `amount_minor` adalah nominal yang DISERAHKAN, bukan yang terpakai,
 *    konsisten dengan `paid_minor`; selisihnya masuk `change_minor`.
 *
 * Kolom lama (`payment_method`, `paid_minor`) tetap dipertahankan sebagai
 * cermin untuk kompatibilitas; untuk penjualan campuran `payment_method`
 * bernilai "mixed" dan rincian sebenarnya ada di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('payment_method', 32);
            $table->bigInteger('amount_minor')->default(0);
            // FR-05: status verifikasi per tender (default "dicatat kasir").
            $table->string('payment_verification', 30)->default('cashier_recorded');
            // Referensi instruksi/manual (mis. nomor transfer, 4 digit kartu).
            $table->string('reference', 191)->nullable();
            $table->string('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'payment_method']);
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_payments');
    }
};
