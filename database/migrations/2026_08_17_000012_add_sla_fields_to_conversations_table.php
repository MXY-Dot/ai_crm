<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ЭТАП 13.6 — SLA Engine. Before this, Conversation.status only ever became
 * 'open'/'pending_operator' — 'closed' had a UI label but nothing ever wrote
 * it. first_response_at/resolved_at are the minimum real timestamps needed
 * to measure response/resolution speed instead of inventing a compliance
 * threshold with nothing to check it against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('first_response_at')->nullable()->after('last_message_at');
            $table->timestamp('resolved_at')->nullable()->after('first_response_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['first_response_at', 'resolved_at']);
        });
    }
};
