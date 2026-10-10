<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 (Identitas perangkat): kredensial token per perangkat (NFR-03,
 * §15). Plaintext token TIDAK pernah disimpan — hanya hash SHA-256 dan prefix
 * untuk ditampilkan. Additive: kolom nullable, tidak merusak data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('token_hash', 64)->nullable()->unique()->after('app_version');
            $table->string('token_prefix', 16)->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['token_hash', 'token_prefix']);
        });
    }
};
