<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-operator "pin to top of my list" bookmark, one row per (conversation, user)
 * — purely personal, like Telegram's own chat pinning. Deliberately separate from
 * `conversations.assigned_user_id` (who's responsible for the customer, shared/
 * visible to everyone, auto-set on first reply — see ConversationReplyController):
 * pinning is each operator's own quick-access shortcut and has zero effect on who
 * owns the conversation. Row existence = pinned; no boolean column needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_pins');
    }
};
