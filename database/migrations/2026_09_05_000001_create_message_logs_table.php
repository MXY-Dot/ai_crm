<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every outbound email/Telegram send the platform makes, one row per
 * recipient -- the audit trail super admin asked for ("что и где когда с
 * кем") so a billing/misunderstanding dispute has a real record instead of
 * "trust us". See App\Support\Messaging\MessageLogger for how rows land
 * here (a global Mail event listener for email, explicit calls at the two
 * Telegram send sites).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20); // mail | telegram
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('status', 20); // sent | failed | blocked
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};
