<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Dedupe marker for the newer event-triggered reminders (created/payment_confirmed/
            // rescheduled/cancelled/completed/3h_before) -- separate from the pre-existing
            // reminder_sent_at column, which stays exactly as-is for the 24h-before reminder.
            $table->jsonb('reminders_sent')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('reminders_sent');
        });
    }
};
