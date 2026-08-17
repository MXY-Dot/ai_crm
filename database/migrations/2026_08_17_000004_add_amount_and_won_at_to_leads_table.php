<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal deal-value concept (ЭТАП 12.2 depends on it — VIP Score needs a real
 * revenue signal, which nothing in the schema tracked before this). `amount` is
 * entered by the operator when moving a Lead to 'won'; `won_at` is stamped
 * automatically (see Lead::booted()) so purchase recency can be computed without
 * relying on `updated_at`, which bumps on any unrelated edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->decimal('amount', 12, 2)->nullable()->after('score');
            $table->timestamp('won_at')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['amount', 'won_at']);
        });
    }
};
