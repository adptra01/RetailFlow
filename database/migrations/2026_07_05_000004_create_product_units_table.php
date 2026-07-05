<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('abbreviation');
            $table->unsignedInteger('conversion_factor')->comment('How many base units');
            $table->boolean('is_base')->default(false);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
            $table->index('is_base');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
