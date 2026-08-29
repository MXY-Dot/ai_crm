<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('outcome')->default('other');
            $table->string('sentiment')->default('neutral');
            $table->string('sentiment_start')->default('neutral');
            $table->unsignedTinyInteger('quality_score')->default(50);
            $table->boolean('is_resolved')->default(false);
            $table->text('unhappy_reason')->nullable();
            $table->text('summary')->nullable();
            $table->text('customer_wanted')->nullable();
            $table->text('ai_action')->nullable();
            $table->text('operator_action')->nullable();
            $table->unsignedTinyInteger('return_probability')->nullable();
            $table->unsignedTinyInteger('sale_probability')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('model_used')->nullable();
            $table->dateTime('analyzed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'company_id', 'outcome']);
            $table->index(['tenant_id', 'company_id', 'sentiment']);
            $table->index(['tenant_id', 'analyzed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_analyses');
    }
};
