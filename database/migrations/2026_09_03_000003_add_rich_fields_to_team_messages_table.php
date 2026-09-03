<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Brings team_messages up to feature parity with the customer-facing
 *  `messages` table (attachments, edit, delete, reply-to) -- same field
 *  names/semantics as that table so the frontend can reuse its exact
 *  MessageAttachment shape and edit/delete conventions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_messages', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('body');
            $table->timestamp('edited_at')->nullable()->after('read_at');
            $table->timestamp('deleted_at')->nullable()->after('edited_at');
            $table->foreignId('reply_to_message_id')->nullable()->after('recipient_id')
                ->constrained('team_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropColumn(['meta', 'edited_at']);
        });
    }
};
