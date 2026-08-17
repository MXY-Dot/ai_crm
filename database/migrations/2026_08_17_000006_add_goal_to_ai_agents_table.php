<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ЭТАП 9.3 — AI Goal Engine. Null = current behavior (no goal-specific prompt guidance). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->string('goal')->nullable()->after('handoff_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->dropColumn('goal');
        });
    }
};
