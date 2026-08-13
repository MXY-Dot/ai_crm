<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-operator read state, one row per (conversation, user). Unread count for a
 * conversation = messages with id > last_read_message_id (message ids are
 * monotonically increasing within a conversation's own insert order, so this is
 * a safe cheap comparison without needing a separate sequence per conversation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reads');
    }
};
