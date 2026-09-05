<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets the calendar show which channel (telegram/whatsapp/instagram/facebook)
// a booking-shaped record came from, same nullable-FK pattern already used by
// customer_feedback/knowledge_gaps for conversation_id. Null means either a
// pre-existing row or one created manually in the CRM, not through a chat channel.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bookings', 'table_reservations', 'room_reservations', 'repair_orders'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('channel_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['bookings', 'table_reservations', 'room_reservations', 'repair_orders'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('channel_id');
            });
        }
    }
};
