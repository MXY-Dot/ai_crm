<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // ТЗ раздел 18 — Telegram-доставка уведомлений сотруднику. Set once the
            // user links their own Telegram via the /link {code} bot command (see
            // TelegramWebhookController) -- the tenant's own already-connected bot,
            // not a separate platform-wide one.
            $table->string('telegram_chat_id')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
