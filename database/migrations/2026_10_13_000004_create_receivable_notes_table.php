<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan penagihan piutang (FR-07: "Catatan penagihan").
 *
 * Satu baris = satu catatan penagihan atas SATU piutang, append-only: koreksi
 * ditulis sebagai catatan baru, bukan editan. `channel` menyimpan cara
 * menghubungi pelanggan, `contacted_at` menyimpan kapan (bisa mundur dari
 * waktu pencatatan), dan `created_by` menyimpan siapa yang menagih.
 *
 * Aditif: tabel baru, tidak menyentuh yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('receivable_id')->index();
            $table->string('channel', 32)->nullable();
            $table->string('note', 1000);
            $table->timestamp('contacted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_notes');
    }
};
