<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->string('provider')->nullable()->index()->after('ai_agent_id');
            $table->string('model')->nullable()->after('provider');
            $table->unsignedInteger('tokens_in')->nullable()->after('model');
            $table->unsignedInteger('tokens_out')->nullable()->after('tokens_in');
            $table->decimal('cost_usd', 10, 6)->nullable()->after('tokens_out');
            $table->unsignedInteger('latency_ms')->nullable()->after('cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropColumn(['provider', 'model', 'tokens_in', 'tokens_out', 'cost_usd', 'latency_ms']);
        });
    }
};
