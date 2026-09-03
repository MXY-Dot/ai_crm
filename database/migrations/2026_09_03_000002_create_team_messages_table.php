<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 1-on-1 direct messages between tenant users -- kept separate from
 *  conversations/messages (customer-facing, Chatwoot-backed) since team chat
 *  has no channel/lead/AI concept at all, just two colleagues talking. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sender_id', 'recipient_id']);
            $table->index(['tenant_id', 'recipient_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_messages');
    }
};
