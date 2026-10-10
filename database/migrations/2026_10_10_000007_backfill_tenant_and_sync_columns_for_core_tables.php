<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfills the sync identity (`uuid`) and owning tenant for rows that
 * existed before the core tables became tenant-aware (plan §21).
 *
 * Irreversible by design: the backfilled values are the historical truth
 * and are intentionally left in place on rollback (forward-fix policy §16).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'users',
        'categories',
        'products',
        'customers',
        'transactions',
        'transaction_items',
        'stock_movements',
    ];

    public function up(): void
    {
        $tenantId = $this->resolveDefaultTenantId();

        foreach ($this->tables as $table) {
            $this->backfillUuid($table);

            DB::table($table)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);
        }
    }

    public function down(): void
    {
        // Intentionally empty — see class docblock.
    }

    private function resolveDefaultTenantId(): string
    {
        $existing = DB::table('tenants')->orderBy('created_at')->value('id');

        if ($existing) {
            return $existing;
        }

        $tenantId = (string) Str::uuid7();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Toko Default',
            'business_type' => 'retail',
            'feature_profile' => 'simple',
            'ui_complexity' => 'simple',
            'onboarding_state' => 'completed',
            'enabled_modules' => json_encode(['cashier', 'products', 'history', 'reports']),
            'currency_code' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function backfillUuid(string $table): void
    {
        DB::table($table)->whereNull('uuid')->chunkById(500, function ($rows) use ($table) {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update([
                    'uuid' => (string) Str::uuid7(),
                ]);
            }
        });
    }
};
