<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 100)->nullable()->unique()->after('sku');
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete()->after('category_id');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete()->after('brand_id');
            $table->decimal('buy_price', 12, 2)->default(0)->after('barcode');
            $table->unsignedInteger('min_stock')->default(0)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['barcode', 'buy_price', 'min_stock']);
        });
    }
};
