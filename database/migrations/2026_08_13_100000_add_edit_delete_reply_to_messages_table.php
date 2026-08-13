<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('edited_at')->nullable()->after('sent_at');
            $table->softDeletes()->after('edited_at');
            $table->foreignId('reply_to_message_id')->nullable()->after('conversation_id')->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropSoftDeletes();
            $table->dropColumn('edited_at');
        });
    }
};
