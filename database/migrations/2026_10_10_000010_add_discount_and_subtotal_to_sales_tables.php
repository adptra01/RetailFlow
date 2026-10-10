<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('total_minor');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('subtotal_minor');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->unsignedBigInteger('discount_minor')->default(0)->after('unit_price_minor');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn(['discount_minor']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['subtotal_minor', 'discount_minor']);
        });
    }
};
