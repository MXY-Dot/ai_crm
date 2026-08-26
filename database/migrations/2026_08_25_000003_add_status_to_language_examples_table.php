<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only APPROVED examples may be surfaced to the model as few-shot guidance —
 * existing rows default to 'approved' since they were already tenant-supplied
 * and already being used unconditionally before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('language_examples', function (Blueprint $table): void {
            $table->string('status')->default('approved')->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('language_examples', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
