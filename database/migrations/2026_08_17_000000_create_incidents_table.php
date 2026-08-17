<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted log of every open/resolved health incident (ЭТАП 16.18) — written by
 * both HealthMonitor (component-level, tenant_id null for platform-wide LLM
 * providers) and EmergencyStateManager (tenant-level, e.g. a tenant's Dify
 * instance or their model's provider going down). Deliberately not tenant-scoped
 * via BelongsToTenant: Super Admin needs to read across all tenants, and
 * platform-wide rows have no tenant at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('component')->index();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open')->index();
            $table->string('cause')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('affected_tenants_count')->nullable();
            $table->unsignedInteger('affected_conversations_count')->default(0);
            $table->timestamp('alerted_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['component', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
