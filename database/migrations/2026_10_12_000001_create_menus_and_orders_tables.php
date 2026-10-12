<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique()->index();
            $table->string('sku')->unique()->index();
            $table->string('barcode')->nullable()->index();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->unsignedInteger('preparation_time')->nullable()->comment('Minutes to prepare');
            $table->boolean('is_available')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->string('image')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('order_number', 50)->unique();
            $table->string('table_number', 20)->nullable()->comment('Table identifier for dine-in');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('change_minor')->default(0);
            $table->string('status', 20)->default('pending'); // pending, preparing, ready, served, paid, cancelled
            $table->string('payment_method', 20)->default('cash');
            $table->string('payment_status', 20)->default('unpaid'); // unpaid, partial, paid
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // cashier who took the order
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('menu_id')->nullable()->index();
            $table->string('menu_name')->comment('Snapshot of menu name at order time');
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->text('notes')->nullable()->comment('Special instructions, modifiers');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('menus');
    }
};
