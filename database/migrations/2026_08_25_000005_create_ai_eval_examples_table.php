<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Held completely separate from language_examples (the Train set fed into
 * prompts) — eval examples must never leak into what the model sees as
 * few-shot guidance, only into automated quality checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_eval_examples', function (Blueprint $table): void {
            $table->id();
            $table->text('input_text');
            $table->text('expected_reply')->nullable();
            $table->string('expected_intent')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_eval_examples');
    }
};
