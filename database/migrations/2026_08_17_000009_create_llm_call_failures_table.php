<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ЭТАП 19.4 — real error log for failed LlmClient::complete() attempts. Before
 * this, failures only reached Log::warning()/HealthMonitor's rolling failure
 * counter, so there was no way to compute an error rate over a chosen period.
 * One row per failed attempt, written from the same branches that already call
 * HealthMonitor::recordFailure().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_call_failures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('cause');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_call_failures');
    }
};
