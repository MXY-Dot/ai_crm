<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cancellation_policies', function (Blueprint $table): void {
            // ТЗ раздел 13 — владелец может установить от 10 до 60 минут, рекомендуемое 15.
            $table->unsignedTinyInteger('hold_minutes')->default(15)->after('no_show_forfeits_prepayment');
        });
    }

    public function down(): void
    {
        Schema::table('cancellation_policies', function (Blueprint $table): void {
            $table->dropColumn('hold_minutes');
        });
    }
};
