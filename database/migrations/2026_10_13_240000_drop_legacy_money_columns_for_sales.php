<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menutup dual-write: kolom uang desimal legacy penjualan dihapus di SEMUA
 * driver (§17.1 "satu sumber kebenaran": uang disimpan integer minor unit).
 *
 * Sejak `add_idempotency_and_minor_units_to_sales_tables`, tabel penjualan
 * menyimpan nilai kebenaran pada kolom `*_minor`, sementara kolom desimal lama
 * (`total_amount`, `paid_amount`, `change_amount`, `unit_price`, `subtotal`,
 * `sales_returns.total`, `sales_return_items.price/subtotal`) dipertahankan
 * hanya sebagai cermin transisional bagi UI pra-rebuild. Cermin itu adalah
 * sumber kebenaran kedua: nilainya bisa menyimpang dari nilai minor, dan
 * penulisnya harus ingat menulis dua tempat.
 *
 * Setelah pembaca beralih (UI memakai alias baca turunan `*_minor`, agregat SQL
 * memakai `*_minor`), cermin tak lagi diperlukan dan dihapus. Migrasi memakai
 * `Schema::hasColumn()` sehingga berlaku di semua driver; pada driver yang
 * kolomnya sudah hilang (mis. instalasi yang pernah menjalankan pembersihan
 * khusus MySQL) migrasi ini tidak berefek.
 *
 * `down()` memulihkan kolom desimal (kembali berisi 0) — bukan rollback data:
 * nilai kebenaran tetap pada `*_minor`.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private array $legacyColumns = [
        'transactions' => ['total_amount', 'paid_amount', 'change_amount'],
        'transaction_items' => ['unit_price', 'subtotal'],
        'sales_returns' => ['total'],
        'sales_return_items' => ['price', 'subtotal'],
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

                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->decimal($column, 12, 2)->default(0);
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
