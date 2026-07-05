<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->string('barcode', 50)->nullable()->unique()->after('abbreviation');
            $table->decimal('price_grosir', 12, 2)->nullable()->after('price');
            $table->decimal('price_reseller', 12, 2)->nullable()->after('price_grosir');
        });
    }

    public function down(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->dropColumn(['barcode', 'price_grosir', 'price_reseller']);
        });
    }
};
