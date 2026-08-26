<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide (not per-tenant), versioned Tajik/Russian language-handling
 * system prompt — Super Admin → Качество AI → Языковые датасеты. Every save
 * inserts a new row and deactivates the previous one, so history is just
 * "every row ever inserted", newest active wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_system_prompts', function (Blueprint $table): void {
            $table->id();
            $table->string('version');
            $table->text('content');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_system_prompts');
    }
};
