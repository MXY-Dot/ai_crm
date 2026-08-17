<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ЭТАП 6.3 — reference dialogue examples (customer message → good reply), fed
 * into AiWorkflow::directLlmReply()'s system prompt as few-shot guidance. Empty
 * by default and populated only by the tenant themselves — WERO doesn't
 * generate example dialogue content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('language_examples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->text('customer_message');
            $table->text('good_reply');
            $table->string('language')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('language_examples');
    }
};
