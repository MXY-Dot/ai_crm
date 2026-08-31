<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // ТЗ раздел 21 — the "Специалист" role only means something once a login
            // is tied back to a real staff profile: this is what lets BookingPolicy
            // scope a specialist to just their own calendar/clients.
            $table->foreignId('employee_id')->nullable()->after('tenant_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
