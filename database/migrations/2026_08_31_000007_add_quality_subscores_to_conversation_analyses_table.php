<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('completeness_score')->nullable()->after('quality_score');
            $table->unsignedTinyInteger('clarity_score')->nullable()->after('completeness_score');
            $table->unsignedTinyInteger('politeness_score')->nullable()->after('clarity_score');
            $table->unsignedSmallInteger('redundant_messages_count')->nullable()->after('politeness_score');
            $table->boolean('had_to_reexplain')->nullable()->after('redundant_messages_count');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_analyses', function (Blueprint $table) {
            $table->dropColumn(['completeness_score', 'clarity_score', 'politeness_score', 'redundant_messages_count', 'had_to_reexplain']);
        });
    }
};
