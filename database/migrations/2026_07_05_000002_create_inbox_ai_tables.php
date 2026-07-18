<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('name');
            $table->string('status')->default('draft')->index();
            $table->string('external_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'name']);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->string('subject');
            $table->string('status')->default('open')->index();
            $table->string('priority')->default('normal');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->text('ai_summary')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('sender_type')->index();
            $table->string('sender_name')->nullable();
            $table->text('body');
            $table->string('external_id')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider')->default('dify');
            $table->string('status')->default('draft')->index();
            $table->unsignedTinyInteger('handoff_threshold')->default(70);
            $table->text('instructions')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'company_id', 'name']);
        });

        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('completed')->index();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->string('intent')->nullable();
            $table->text('summary')->nullable();
            $table->string('next_action')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_agents');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('channels');
    }
};