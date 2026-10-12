<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode B — Gateway (plan §14): "Rekonsiliasi" + "Penanganan timeout".
 *
 * Satu baris = hasil pemeriksaan ulang satu charge ke penyedia pada satu
 * putaran rekonsiliasi (`run_id`). Append-only seperti `payment_webhook_events`:
 * putaran berikutnya menambah baris, tidak mengubah yang lama, sehingga riwayat
 * "apa yang penyedia katakan" dapat ditelusuri.
 *
 * `outcome`: changed (status berubah dan diterapkan), unchanged (status sama),
 * timeout (penyedia tidak menjawab/tidak dikenal — yang TIDAK boleh dianggap
 * gagal, §14), skipped (transisi tidak sah, hanya untuk pemeriksaan manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_charge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_reference', 191);
            $table->string('status_before', 30);
            $table->string('status_after', 30);
            $table->string('verification_before', 30)->nullable();
            $table->string('verification_after', 30)->nullable();
            $table->string('outcome', 20);
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('run_id');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_entries');
    }
};
