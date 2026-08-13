<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide key/value settings, not scoped to any tenant — e.g. the Groq
 * API key WERO manages centrally (see App\Support\Integrations\PlatformSettings),
 * and the primary/backup LLM provider choice from the Super Admin "LLM-провайдеры"
 * screen. Distinct from tenants.settings, which is per-tenant BYOK/config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
