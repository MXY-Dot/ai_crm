<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide catalog (not tenant-scoped) — see WERO_BUSINESS_MODULES_TZ.md
 * section 2/23. default_modules is an array of module keys from
 * App\Support\Business\ModuleRegistry, applied to a company's own
 * company_modules rows at onboarding time (a snapshot, not a live link --
 * editing a business type's defaults later doesn't retroactively change
 * companies that already onboarded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('default_modules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_types');
    }
};
