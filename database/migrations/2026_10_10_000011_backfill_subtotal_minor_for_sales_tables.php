<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills subtotal_minor for historical sales.
 *
 * Old sales never had a discount, so subtotal == total. The backfill is
 * idempotent: rows that already carry a subtotal are skipped.
 *
 * Irreversible by design (forward-fix policy §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                if ((int) $row->subtotal_minor !== 0) {
                    continue;
                }

                DB::table('transactions')->where('id', $row->id)->update([
                    'subtotal_minor' => (int) $row->total_minor,
                    'discount_minor' => 0,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty — see class docblock.
    }
};
