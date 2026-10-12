<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Retur penjualan sebagai dokumen kelas satu (FR-08 "Retur penjualan dengan
 * alasan", FR-09 "Retur dan pembatalan", §17.4).
 *
 * Tabel `sales_returns`/`sales_return_items` berasal dari aplikasi lama:
 * identitasnya hanya bigint, uangnya desimal, tanpa tenant, tanpa idempotensi,
 * dan tanpa jejak kebijakan stok. Migrasi ini bersifat aditif — kolom lama
 * (`total`, `price`, `subtotal`) dipertahankan sebagai cermin desimal
 * transisional seperti pada `transactions`/`transaction_items`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Retur untuk penjualan tunai tanpa pelanggan (walk-in) harus mungkin:
        // pelanggan tidak wajib ada di nota penjualan.
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedInteger('version')->default(1)->after('user_id');
            $table->softDeletes()->after('version');
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('reason');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('subtotal_minor');
            $table->unsignedBigInteger('total_minor')->default(0)->after('discount_minor');
            // Koreksi pembayaran (§17.4): bagian nilai retur yang mengoreksi
            // piutang, dan bagian yang benar-benar dikembalikan sebagai kas.
            $table->unsignedBigInteger('receivable_adjustment_minor')->default(0)->after('total_minor');
            $table->unsignedBigInteger('refund_minor')->default(0)->after('receivable_adjustment_minor');
            // §17.4: pengembalian stok hanya sesuai kebijakan retur.
            $table->boolean('stock_restored')->default(false)->after('refund_minor');
            $table->string('location_id')->nullable()->after('stock_restored');
            $table->string('device_id')->nullable()->after('location_id');
            $table->string('client_request_id', 64)->nullable()->after('device_id');
        });

        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('uuid');
            $table->unsignedBigInteger('unit_price_minor')->default(0)->after('qty');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('unit_price_minor');
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('discount_minor');
            $table->unsignedInteger('version')->default(1)->after('reason');
            $table->softDeletes()->after('version');
        });

        $this->backfill();

        // Keunikan identitas sinkronisasi & idempotensi (§11.1, §17.1).
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->unique('uuid');
            $table->unique('client_request_id');
        });

        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    /**
     * Isi identitas & nilai minor untuk record lama dari cermin desimalnya.
     *
     * IDR memiliki minor exponent 0 (`config/pos.php`), jadi minor = nilai
     * rupiah bulat — konvensi yang sama dipakai backfill tabel penjualan
     * (`2026_10_10_000009`): TIDAK dikali 100.
     */
    private function backfill(): void
    {
        DB::table('sales_returns')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                $totalMinor = (int) round((float) ($record->total ?? 0));

                DB::table('sales_returns')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                    'subtotal_minor' => $totalMinor,
                    'discount_minor' => 0,
                    'total_minor' => $totalMinor,
                ]);
            }
        });

        DB::table('sales_return_items')->whereNull('uuid')->orderBy('id')->chunk(100, function ($records) {
            foreach ($records as $record) {
                DB::table('sales_return_items')->where('id', $record->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $record->tenant_id ?? null,
                    'unit_price_minor' => (int) round((float) ($record->price ?? 0)),
                    'discount_minor' => 0,
                    'subtotal_minor' => (int) round((float) ($record->subtotal ?? 0)),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn([
                'uuid', 'tenant_id', 'unit_price_minor', 'discount_minor',
                'subtotal_minor', 'version', 'deleted_at',
            ]);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropUnique(['client_request_id']);
            $table->dropUnique(['uuid']);
            $table->dropColumn([
                'uuid', 'tenant_id', 'version', 'deleted_at', 'subtotal_minor',
                'discount_minor', 'total_minor', 'receivable_adjustment_minor',
                'refund_minor', 'stock_restored', 'location_id', 'device_id',
                'client_request_id',
            ]);
        });
    }
};
