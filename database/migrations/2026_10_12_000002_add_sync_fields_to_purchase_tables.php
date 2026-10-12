<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns without unique constraints first
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedInteger('version')->default(1)->after('tenant_id');
            $table->softDeletes()->after('version');
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('notes');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('subtotal_minor');
            $table->unsignedBigInteger('total_minor')->default(0)->after('discount_minor');
            $table->string('location_id')->nullable()->after('user_id');
            $table->string('device_id')->nullable()->after('location_id');
            $table->string('client_request_id')->nullable()->after('device_id');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedBigInteger('unit_price_minor')->default(0)->after('quantity');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('unit_price_minor');
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('discount_minor');
            $table->unsignedInteger('version')->default(1)->after('received_quantity');
            $table->softDeletes()->after('version');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedInteger('version')->default(1)->after('notes');
            $table->softDeletes()->after('version');
            $table->string('location_id')->nullable()->after('user_id');
            $table->string('device_id')->nullable()->after('location_id');
            $table->string('client_request_id')->nullable()->after('device_id');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('goods_receipt_id')->index();
            $table->unsignedBigInteger('purchase_order_item_id')->index();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->onDelete('cascade');
            $table->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->onDelete('restrict');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedInteger('version')->default(1)->after('user_id');
            $table->softDeletes()->after('version');
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('reason');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('subtotal_minor');
            $table->unsignedBigInteger('total_minor')->default(0)->after('discount_minor');
            $table->string('location_id')->nullable()->after('user_id');
            $table->string('device_id')->nullable()->after('location_id');
            $table->string('client_request_id')->nullable()->after('device_id');
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedBigInteger('price_minor')->default(0)->after('qty');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('price_minor');
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('discount_minor');
            $table->unsignedInteger('version')->default(1)->after('reason');
            $table->softDeletes()->after('version');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedInteger('version')->default(1)->after('user_id');
            $table->softDeletes()->after('version');
            $table->unsignedBigInteger('amount_minor')->default(0)->after('notes');
            $table->string('location_id')->nullable()->after('user_id');
            $table->string('device_id')->nullable()->after('location_id');
            $table->string('client_request_id')->nullable()->after('device_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedInteger('version')->default(1)->after('balance');
            $table->softDeletes()->after('version');
            $table->unsignedBigInteger('balance_minor')->default(0)->after('description');
        });

        // Generate UUIDs for existing records
        $this->generateUuids();

        // Add unique constraints after populating
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unique('uuid');
            $table->dropColumn('total_amount');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unique('uuid');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->unique('uuid');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->unique('uuid');
            $table->dropColumn('total');
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->unique('uuid');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->unique('uuid');
            $table->dropColumn('amount');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unique('uuid');
            $table->dropColumn('balance');
        });
    }

    private function generateUuids(): void
    {
        // Purchase Orders
        DB::table('purchase_orders')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                DB::table('purchase_orders')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                ]);
            }
        });

        // Purchase Order Items
        DB::table('purchase_order_items')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                DB::table('purchase_order_items')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                    'unit_price_minor' => (int) round(($record->unit_price ?? 0) * 100),
                    'discount_minor' => 0,
                    'subtotal_minor' => (int) round(($record->subtotal ?? 0) * 100),
                ]);
            }
        });

        // Goods Receipts
        DB::table('goods_receipts')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                DB::table('goods_receipts')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                ]);
            }
        });

        // Purchase Returns
        DB::table('purchase_returns')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                DB::table('purchase_returns')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                    'subtotal_minor' => (int) round(($record->total ?? 0) * 100),
                    'discount_minor' => 0,
                    'total_minor' => (int) round(($record->total ?? 0) * 100),
                ]);
            }
        });

        // Purchase Return Items
        DB::table('purchase_return_items')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                DB::table('purchase_return_items')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                    'price_minor' => (int) round(($record->price ?? 0) * 100),
                    'discount_minor' => 0,
                    'subtotal_minor' => (int) round(($record->subtotal ?? 0) * 100),
                ]);
            }
        });

        // Supplier Payments
        DB::table('supplier_payments')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                DB::table('supplier_payments')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                    'amount_minor' => (int) round(($record->amount ?? 0) * 100),
                ]);
            }
        });

        // Suppliers
        DB::table('suppliers')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                DB::table('suppliers')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                    'balance_minor' => (int) round(($record->balance ?? 0) * 100),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('total_amount', 12, 2)->default(0)->after('notes');
            $table->dropColumn(['uuid', 'tenant_id', 'version', 'deleted_at', 'subtotal_minor', 'discount_minor', 'total_minor', 'location_id', 'device_id', 'client_request_id']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'tenant_id', 'unit_price_minor', 'discount_minor', 'subtotal_minor', 'version', 'deleted_at']);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'tenant_id', 'version', 'deleted_at', 'location_id', 'device_id', 'client_request_id']);
        });

        Schema::dropIfExists('goods_receipt_items');

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('total', 12, 2)->default(0)->after('reason');
            $table->dropColumn(['uuid', 'tenant_id', 'version', 'deleted_at', 'subtotal_minor', 'discount_minor', 'total_minor', 'location_id', 'device_id', 'client_request_id']);
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'tenant_id', 'price_minor', 'discount_minor', 'subtotal_minor', 'version', 'deleted_at']);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('amount', 12, 2)->default(0)->after('notes');
            $table->dropColumn(['uuid', 'tenant_id', 'version', 'deleted_at', 'amount_minor', 'location_id', 'device_id', 'client_request_id']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('balance', 12, 2)->default(0)->after('description');
            $table->dropColumn(['uuid', 'tenant_id', 'version', 'deleted_at', 'balance_minor']);
        });
    }
};
