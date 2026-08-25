<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ЭТАП 19.7 — FAQ Gap Detection. Every time AiWorkflow's anti-hallucination
 * guard fires (nothing in the tenant's knowledge base was actually relevant
 * to the customer's question), that's a real signal the knowledge base is
 * missing something — logged here instead of just silently degrading the
 * reply, so Super Admin can see which questions companies' knowledge bases
 * don't cover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_gaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('customer_message');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_gaps');
    }
};
