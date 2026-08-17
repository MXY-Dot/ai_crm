<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ЭТАП 7.1/7.2 — AI Personality Engine. Same shape as the `goal` column
 * (ЭТАП 9.3): a free string, presets offered in the UI, null = current
 * behavior (no persona instruction added to the prompt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->string('persona')->nullable()->after('goal');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->dropColumn('persona');
        });
    }
};
