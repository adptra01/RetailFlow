<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyamakan skema pembelian/persediaan di SEMUA driver (paritas untuk tes).
 *
 * `2026_10_12_000003_finish_purchase_sync_fields` menghapus kolom uang legacy
 * (desimal) hanya bila driver-nya MySQL/MariaDB — implementasinya memakai
 * `SHOW COLUMNS` yang memang khusus MySQL. Akibatnya, pada driver lain
 * (mis. sqlite yang dipakai tes) kolom legacy itu masih ada dan `NOT NULL`
 * tanpa nilai default, sehingga aksi domain yang (secara benar) hanya menulis
 * kolom `*_minor` akan gagal di tes padahal berjalan normal di produksi.
 *
 * Migrasi ini menutup selisih itu dengan cara yang sama seperti migrasi
 * sebelumnya, tetapi memakai `Schema::hasColumn()` sehingga berlaku di semua
 * driver: kolom legacy yang masih tersisa dihapus, kolom yang sudah hilang
 * (MySQL) dilewati tanpa efek.
 *
 * Tidak ada data yang hilang: nilai kebenaran sudah pindah ke kolom
 * `*_minor`, dan pada MySQL kolom legacy ini sudah dihapus lebih dulu.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private array $legacyColumns = [
        'purchase_orders' => ['total_amount'],
        'purchase_order_items' => ['unit_price', 'subtotal'],
        'purchase_returns' => ['total'],
        'purchase_return_items' => ['price', 'subtotal'],
        'supplier_payments' => ['amount'],
        'suppliers' => ['balance'],
    ];

    public function up(): void
    {
        foreach ($this->legacyColumns as $table => $columns) {
            $this->dropColumnsIfExist($table, $columns);
        }
    }

    public function down(): void
    {
        foreach ($this->legacyColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $table) use ($column) {
                    $table->decimal($column, 12, 2)->default(0);
                });
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumnsIfExist(string $table, array $columns): void
    {
        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing) {
            $blueprint->dropColumn($existing);
        });
    }
};
