<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only run on MySQL/MariaDB
        if (! $this->isMySQL()) {
            return;
        }

        // Drop old decimal columns if they exist
        $this->dropColumnIfExists('purchase_orders', 'total_amount');
        $this->dropColumnIfExists('purchase_order_items', 'unit_price');
        $this->dropColumnIfExists('purchase_order_items', 'subtotal');
        $this->dropColumnIfExists('purchase_returns', 'total');
        $this->dropColumnIfExists('purchase_return_items', 'price');
        $this->dropColumnIfExists('purchase_return_items', 'subtotal');
        $this->dropColumnIfExists('supplier_payments', 'amount');
        $this->dropColumnIfExists('suppliers', 'balance');

        // Add unique constraints if they don't exist
        $this->addUniqueIfNotExists('purchase_orders', 'uuid');
        $this->addUniqueIfNotExists('purchase_order_items', 'uuid');
        $this->addUniqueIfNotExists('goods_receipts', 'uuid');
        $this->addUniqueIfNotExists('purchase_returns', 'uuid');
        $this->addUniqueIfNotExists('purchase_return_items', 'uuid');
        $this->addUniqueIfNotExists('supplier_payments', 'uuid');
        $this->addUniqueIfNotExists('suppliers', 'uuid');
    }

    private function isMySQL(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (! $this->isMySQL()) {
            return;
        }

        $columns = DB::select("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (! empty($columns)) {
            Schema::table($table, function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    private function addUniqueIfNotExists(string $table, string $column): void
    {
        if (! $this->isMySQL()) {
            return;
        }

        $indexes = DB::select("SHOW INDEX FROM `$table` WHERE Key_name = '{$table}_{$column}_unique'");
        if (empty($indexes)) {
            Schema::table($table, function (Blueprint $table) use ($column) {
                $table->unique($column);
            });
        }
    }

    public function down(): void
    {
        if (! $this->isMySQL()) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('total_amount', 12, 2)->default(0)->after('notes');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('total', 12, 2)->default(0)->after('reason');
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('price', 12, 2);
            $table->decimal('subtotal', 12, 2);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('amount', 12, 2)->default(0)->after('notes');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->decimal('balance', 12, 2)->default(0)->after('description');
        });
    }
};
