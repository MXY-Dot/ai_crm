<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ЭТАП 18 — Marketing Campaigns. Deliberately no send-tracking columns
 * (delivered/opened/replied) — WERO never sends the campaign itself (see
 * CampaignController::markSent()'s own docblock): no consent tracking exists
 * anywhere in this schema, and WhatsApp/Telegram both restrict unsolicited
 * bulk messaging, the same constraint already documented on
 * FollowUpAbandonedConversationsCommand (Stage 13) and
 * PostServiceFollowUpCommand (Stage 17). An operator reviews the audience +
 * offer_text and sends it themselves from their own account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('offer_text');
            $table->string('segment', 40)->nullable();
            $table->unsignedInteger('min_purchases')->nullable();
            $table->unsignedInteger('inactive_days')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
