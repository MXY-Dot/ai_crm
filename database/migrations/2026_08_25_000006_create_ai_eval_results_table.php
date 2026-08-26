<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_eval_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_eval_example_id')->constrained()->cascadeOnDelete();
            $table->string('run_id');
            $table->string('provider');
            $table->string('model');
            $table->text('response_text')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->boolean('success')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_eval_results');
    }
};
