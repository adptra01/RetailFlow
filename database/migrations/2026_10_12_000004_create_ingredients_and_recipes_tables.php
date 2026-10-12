<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabel `recipes` + `recipe_ingredients` (desain resep berbasis produk
        // lama, migrasi 2026_10_11_000006) sudah digantikan desain bahan baku
        // mandiri (Increment 40). Tabel itu kosong dan tidak direferensikan
        // kode mana pun, sehingga aman di-drop ulang di sini.
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('ingredients');

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->index();
            $table->string('sku')->nullable()->index();
            $table->string('unit', 20)->default('pcs')->comment('Base unit: gr, ml, pcs, pack, etc.');
            // Signed: auto-deduct resep saat order `pending→preparing` boleh
            // membuat stok negatif (keputusan Increment 40) hingga stok masuk.
            $table->bigInteger('stock_qty')->default(0)->comment('Stock in base unit; may be negative');
            $table->unsignedBigInteger('cost_minor')->default(0)->comment('Cost per 1 base unit, IDR minor');
            $table->unsignedBigInteger('min_stock_qty')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('menu_id')->index();
            $table->unsignedBigInteger('ingredient_id')->index();
            $table->unsignedBigInteger('quantity')->default(0)->comment('Amount of ingredient per 1 menu unit');
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['menu_id', 'ingredient_id']);
            $table->foreign('menu_id')->references('id')->on('menus')->onDelete('cascade');
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('ingredients');
    }
};
