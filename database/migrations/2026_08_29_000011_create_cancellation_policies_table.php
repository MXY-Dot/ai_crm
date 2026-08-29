<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // service_id null = company-wide default policy; a non-null row overrides it for that one service.
        Schema::create('cancellation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('free_reschedule_hours')->default(48);
            $table->unsignedInteger('late_reschedule_hours')->default(24);
            $table->unsignedTinyInteger('max_client_reschedules')->default(2);
            $table->boolean('no_show_forfeits_prepayment')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
