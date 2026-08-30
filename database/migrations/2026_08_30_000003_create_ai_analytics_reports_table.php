<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analytics_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period_type');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('content');
            $table->json('snapshot')->nullable();
            $table->string('generated_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'period_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analytics_reports');
    }
};
