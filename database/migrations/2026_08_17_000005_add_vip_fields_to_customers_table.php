<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached VIP scoring output (ЭТАП 12.2) — recalculated by VipScoreCalculator
 * whenever a related Lead changes (see Lead::booted()), not computed fresh on
 * every page render. purchases_count/total_revenue/last_purchase_at are also
 * cached here (not just re-derivable from leads) so the VIP table doesn't need
 * an aggregate query per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('vip_score')->default(0);
            $table->string('vip_status')->default('regular')->index();
            $table->text('vip_reason')->nullable();
            $table->string('segment')->nullable();
            $table->unsignedInteger('purchases_count')->default(0);
            $table->decimal('total_revenue', 12, 2)->default(0);
            $table->timestamp('last_purchase_at')->nullable();
            $table->timestamp('vip_calculated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['vip_score', 'vip_status', 'vip_reason', 'segment', 'purchases_count', 'total_revenue', 'last_purchase_at', 'vip_calculated_at']);
        });
    }
};
