<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name', 200);
            $table->string('code', 50)->nullable()->index();
            // FR-06: jenis diskon promosi — persen dari subtotal atau nominal tetap.
            $table->string('discount_type', 20)->default('percent')->comment('percent | nominal');
            // Besaran: persen (0-100) untuk percent, nominal minor (IDR) untuk nominal.
            $table->unsignedInteger('discount_value')->default(0);
            // Ruang lingkup: produk tertentu (product) atau seluruh transaksi (transaction).
            $table->string('scope', 20)->default('transaction')->comment('product | transaction');
            $table->unsignedBigInteger('min_purchase_minor')->default(0)->comment('Minimum subtotal transaksi agar promo berlaku');
            $table->unsignedBigInteger('max_discount_minor')->nullable()->comment('Batas maksimum nilai diskon (cap)');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            // Tie-break bila dua promosi memberi diskon sama besar: prioritas tertinggi menang.
            $table->unsignedInteger('priority')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('promotion_products', function (Blueprint $table) {
            $table->id();
            // Pivot M2M (bukan tabel sync): uuid hanya identitas opsional — nullable.
            $table->uuid('uuid')->nullable()->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('promotion_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['promotion_id', 'product_id']);
            $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        // FR-06: transaksi menyimpan snapshot promosi saat penjualan terjadi.
        // Perubahan konfigurasi promosi tidak pernah mengubah transaksi historis.
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('promotion_id')->nullable()->after('discount_minor');
            $table->foreign('promotion_id')->references('id')->on('promotions')->nullOnDelete();
            $table->string('promotion_name', 200)->nullable()->after('promotion_id');
            $table->unsignedBigInteger('promotion_discount_minor')->nullable()->after('promotion_name');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->unsignedBigInteger('promotion_id')->nullable()->after('discount_minor');
            $table->foreign('promotion_id')->references('id')->on('promotions')->nullOnDelete();
            $table->string('promotion_name', 200)->nullable()->after('promotion_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['promotion_id']);
            $table->dropColumn(['promotion_id', 'promotion_name', 'promotion_discount_minor']);
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropForeign(['promotion_id']);
            $table->dropColumn(['promotion_id', 'promotion_name']);
        });

        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotions');
    }
};
