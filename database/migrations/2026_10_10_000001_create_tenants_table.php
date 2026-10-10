<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('business_type', 40)->default('retail');
            $table->string('feature_profile', 40)->default('simple');
            $table->string('ui_complexity', 20)->default('simple');
            $table->string('onboarding_state', 30)->default('pending');
            $table->json('enabled_modules')->nullable();
            $table->char('currency_code', 3)->default('IDR');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
