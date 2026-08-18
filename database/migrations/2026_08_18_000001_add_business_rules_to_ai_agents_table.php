<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->unsignedTinyInteger('max_discount_percent')->nullable()->after('persona');
            $table->json('forbidden_topics')->nullable()->after('max_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $table): void {
            $table->dropColumn(['max_discount_percent', 'forbidden_topics']);
        });
    }
};
