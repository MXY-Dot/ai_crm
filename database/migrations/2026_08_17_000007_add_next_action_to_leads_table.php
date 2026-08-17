<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ЭТАП 9.5 — Next Best Action, persisted from the latest AiDecision (AiWorkflow::process()) instead of only living transiently in ai_runs.payload. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('next_action')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('next_action');
        });
    }
};
