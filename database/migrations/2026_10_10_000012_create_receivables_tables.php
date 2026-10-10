<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Piutang dasar (FR-07 / plan §17.2).
 *
 * Mapping plan -> schema:
 *  - plan `receivables.sale_id`      -> `transaction_id` (sales live in the
 *    legacy `transactions` table during the rebuild).
 *  - plan `receivables.payments`     -> `receivable_payments`, dengan
 *    `payment_id` (nullable, tanpa FK) dicadangkan untuk tabel `payments`
 *    yang dibuat pada Fase 5 (Payment).
 *  - `remaining_minor` adalah cache atomic "sisa saldo" (FR-07: sisa saldo);
 *    hanya diubah oleh domain actions, tidak boleh diedit langsung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('transaction_id')->index();
            $table->unsignedBigInteger('original_amount_minor')->default(0);
            $table->unsignedBigInteger('remaining_minor')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->string('status', 16)->default('open'); // open | closed
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('receivable_id')->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index(); // reserved: Fase 5 `payments`
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('payment_method', 32)->default('cash');
            $table->unsignedBigInteger('recorded_by')->nullable()->index();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->string('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
        Schema::dropIfExists('receivables');
    }
};
