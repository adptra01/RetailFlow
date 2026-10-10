<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfills receivables for historical credit sales (payment_status='credit').
 *
 * Idempotent: rows whose transaction already has a receivable are skipped,
 * so re-running on a partially migrated dataset fills only the gaps.
 *
 * Irreversible by design (forward-fix policy §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->where('payment_status', 'credit')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $already = DB::table('receivables')
                        ->where('transaction_id', $row->id)
                        ->exists();

                    if ($already) {
                        continue;
                    }

                    $unpaid = max(0, (int) $row->total_minor - (int) $row->paid_minor);

                    if ($unpaid <= 0) {
                        continue;
                    }

                    DB::table('receivables')->insert([
                        'uuid' => Str::uuid7()->toString(),
                        'tenant_id' => $row->tenant_id,
                        'customer_id' => $row->customer_id,
                        'transaction_id' => $row->id,
                        'original_amount_minor' => $unpaid,
                        'remaining_minor' => $unpaid,
                        'due_at' => null,
                        'status' => 'open',
                        'version' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally empty — see class docblock.
    }
};
