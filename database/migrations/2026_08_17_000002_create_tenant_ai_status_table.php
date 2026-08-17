<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tenant, tracking whether their AI is currently answering normally
 * or has genuinely failed over to the emergency human-handoff path (ЭТАП 16.3).
 * Derived from what AiWorkflow::decision() actually observed, not from
 * health_components directly — a tenant whose Dify AND direct-LLM provider are
 * both down is in 'emergency'; a tenant who simply never configured either is not
 * (that's a normal unconfigured state, not an incident).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ai_status', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode')->default('normal')->index();
            $table->string('reason')->nullable();
            $table->timestamp('since')->nullable();
            $table->foreignId('active_incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->unsignedTinyInteger('consecutive_ai_failures')->default(0);
            $table->unsignedTinyInteger('consecutive_recoveries')->default(0);
            $table->boolean('manual_override')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_status');
    }
};
