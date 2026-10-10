<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_name', 100);
            $table->string('platform', 30);
            $table->string('app_version', 30)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('last_sync_cursor', 100)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
