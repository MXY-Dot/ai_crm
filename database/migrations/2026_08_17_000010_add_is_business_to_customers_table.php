<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ЭТАП 12.3 — no automatic signal exists anywhere in this schema for "is this
 * customer a business," unlike VIP status which could be derived from real
 * purchase data. Manual, operator-set flag is the only honest option.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('is_business')->default(false)->after('segment');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('is_business');
        });
    }
};
