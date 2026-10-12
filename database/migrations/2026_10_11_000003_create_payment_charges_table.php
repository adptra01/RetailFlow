<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode B — Gateway (plan §14): "Penyimpanan reference ID provider".
 *
 * Satu baris = satu transaksi pembayaran di penyedia. Callback/webhook dan
 * rekonsiliasi mencocokkan pembayaran dengan penjualan kita lewat
 * `provider_reference`, dan `client_request_id` (unik per penyedia) membuat
 * pembuatan transaksi idempoten (FR-04) — percobaan ulang tidak boleh
 * menghasilkan dua transaksi pembayaran.
 *
 * `transaction_id` nullable: baris tetap sah bila penjualan lokal belum
 * tersimpan saat penyedia menjawab (webhook boleh datang lebih dulu).
 *
 * Kolom ini TIDAK menyimpan kredensial apa pun (FR-05, §15); `payload` hanya
 * respons penyedia tanpa secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_reference', 191);
            $table->string('client_request_id', 64);
            $table->string('status', 30)->default('pending');
            $table->string('payment_verification', 30)->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('IDR');
            $table->json('payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // Satu transaksi penyedia hanya boleh dikenali sekali per penyedia.
            $table->unique(['provider', 'provider_reference']);
            // Idempotensi pembuatan transaksi pembayaran (FR-04).
            $table->unique(['provider', 'client_request_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'payment_verification']);
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_charges');
    }
};
