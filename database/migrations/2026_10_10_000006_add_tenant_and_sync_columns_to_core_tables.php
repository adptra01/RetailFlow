<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business tables that participate in tenant scoping and sync (plan §12).
     *
     * @var list<string>
     */
    private array $syncTables = [
        'categories',
        'products',
        'customers',
        'transactions',
        'transaction_items',
        'stock_movements',
    ];

    /**
     * Tables whose history must be preserved through soft deletes.
     *
     * @var list<string>
     */
    private array $softDeletable = [
        'categories',
        'products',
        'customers',
        'transactions',
    ];

    public function up(): void
    {
        foreach ($this->syncTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->uuid('uuid')->nullable();
                $blueprint->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $blueprint->unsignedBigInteger('version')->default(1);

                if (in_array($table, $this->softDeletable, true)) {
                    $blueprint->softDeletes();
                }

                $blueprint->unique('uuid');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->unique('uuid');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropConstrainedForeignId('device_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('uuid');
            $table->dropColumn('is_active');
        });

        foreach ($this->syncTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique(['uuid']);
                $blueprint->dropConstrainedForeignId('tenant_id');
                $blueprint->dropColumn('uuid');
                $blueprint->dropColumn('version');

                if (in_array($table, $this->softDeletable, true)) {
                    $blueprint->dropSoftDeletes();
                }
            });
        }
    }
};
