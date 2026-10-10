<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('client_request_id', 64)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('total_minor')->default(0)->after('total_amount');
            $table->unsignedBigInteger('paid_minor')->default(0)->after('paid_amount');
            $table->unsignedBigInteger('change_minor')->default(0)->after('change_amount');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price_minor')->default(0)->after('unit_price');
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn(['unit_price_minor', 'subtotal_minor']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['client_request_id']);
            $table->dropColumn(['client_request_id', 'total_minor', 'paid_minor', 'change_minor']);
        });
    }
};
