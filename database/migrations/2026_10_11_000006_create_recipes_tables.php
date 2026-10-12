<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete(); // Produk yang dihasilkan resep ini
            $table->decimal('yield_quantity', 12, 4); // Berapa banyak produk yang dihasilkan
            $table->foreignId('yield_unit_id')->constrained('product_units')->cascadeOnDelete(); // Satuan dari yield_quantity
            $table->timestamps();
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_product_id')->constrained('products')->cascadeOnDelete(); // Bahan baku berupa produk lain
            $table->decimal('quantity', 12, 4); // Kuantitas bahan baku
            $table->foreignId('unit_id')->constrained('product_units')->cascadeOnDelete(); // Satuan dari bahan baku
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
