<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliberately separate from the existing `industry` column (a coarse
 * 13-value tone hint used by AiWorkflow, resources/js/lib/industries.ts) --
 * business_type is the TZ's much more granular 34-option catalog that
 * drives module defaults, not AI tone. Both fields stay; nothing renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('business_type_id')->nullable()->after('industry')->constrained()->nullOnDelete();
            $table->string('business_type_other')->nullable()->after('business_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_type_id');
            $table->dropColumn('business_type_other');
        });
    }
};
