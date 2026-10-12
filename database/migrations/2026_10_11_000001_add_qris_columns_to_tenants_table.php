<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-05: QRIS statis milik merchant disimpan sebagai konfigurasi tenant
 * (satu toko = satu QRIS). Additive: kolom nullable baru, baris lama tidak
 * berubah. Payload bukan rahasia (QR yang dipajang ke pembeli), jadi boleh
 * tersimpan di database lokal; kredensial gateway kelak TIDAK disimpan
 * plaintext (FR-05) dan tidak memakai kolom ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('qris_payload')->nullable()->after('currency_code');
            $table->string('qris_merchant_name', 150)->nullable()->after('qris_payload');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['qris_payload', 'qris_merchant_name']);
        });
    }
};
