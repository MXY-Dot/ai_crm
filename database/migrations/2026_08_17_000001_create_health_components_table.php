<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circuit-breaker state, one row per (component, tenant_id) pair — tenant_id is
 * null for the 5 platform-managed LLM providers (one key for the whole platform,
 * see PlatformSettings) and set for per-tenant Dify instances (BYOK, see
 * TenantIntegrationSettings::difyApiKey/difyUrl). `status` flips to 'down' after
 * HealthMonitor::FAILURE_THRESHOLD consecutive failures and actually short-circuits
 * further calls (LlmClient/DifyClient check isOpen() before attempting the real
 * HTTP call) — this table IS the breaker, not just a log of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_components', function (Blueprint $table): void {
            $table->id();
            $table->string('component');
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('up')->index();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error')->nullable();
            $table->foreignId('open_incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->timestamps();
            $table->unique(['component', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_components');
    }
};
