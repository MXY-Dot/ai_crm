<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_departures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('tour_id')->constrained()->cascadeOnDelete();
            $table->date('departure_date');
            $table->date('return_date')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status')->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['tour_id']);
            $table->index(['departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_departures');
    }
};
