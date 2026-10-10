<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the integer minor-unit columns from the legacy decimal columns.
 *
 * IDR has a minor exponent of 0, so minor == the whole rupiah amount
 * (config/pos.php: POS_CURRENCY_MINOR_EXPONENT). Existing sales stay intact;
 * new sales written by the domain write both representations until the
 * legacy decimal columns are retired.
 *
 * Irreversible by design (forward-fix policy §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                if ((int) $row->total_minor !== 0 || (int) $row->paid_minor !== 0) {
                    continue;
                }

                DB::table('transactions')->where('id', $row->id)->update([
                    'total_minor' => (int) round((float) $row->total_amount),
                    'paid_minor' => (int) round((float) $row->paid_amount),
                    'change_minor' => (int) round((float) $row->change_amount),
                ]);
            }
        });

        DB::table('transaction_items')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                if ((int) $row->unit_price_minor !== 0 || (int) $row->subtotal_minor !== 0) {
                    continue;
                }

                DB::table('transaction_items')->where('id', $row->id)->update([
                    'unit_price_minor' => (int) round((float) $row->unit_price),
                    'subtotal_minor' => (int) round((float) $row->subtotal),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty — see class docblock.
    }
};
