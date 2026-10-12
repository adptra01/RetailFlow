<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode B — Gateway (plan §14): "Idempotensi callback" + jejak webhook.
 *
 * Setiap callback yang tanda tangannya SAH dicatat di sini sekali saja:
 * `unique(['provider', 'event_id'])` adalah penjaga idempotensi — pengiriman
 * ulang peristiwa yang sama tidak pernah diterapkan dua kali (§15 "mencegah
 * replay").
 *
 * `tenant_id` nullable dan tanpa global scope: webhook datang tanpa konteks
 * tenant (§12), tenant baru diketahui setelah referensi cocok. Baris dengan
 * referensi tak dikenal tetap dicatat sebagai diagnostik, bukan dibuang
 * diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->string('event_id', 191);
            $table->string('provider_reference', 191)->nullable();
            $table->string('provider_status', 30)->nullable();
            $table->string('outcome', 20);
            $table->string('payment_verification', 30)->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['provider', 'provider_reference']);
            $table->index(['provider', 'received_at']);
            $table->index(['tenant_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
