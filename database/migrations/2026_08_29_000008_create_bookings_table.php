<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('resources')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('temp_hold');
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('prepayment_amount', 10, 2)->default(0);
            $table->string('prepayment_status')->default('none');
            $table->dateTime('hold_expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->timestamps();
            $table->index(['employee_id', 'starts_at', 'ends_at']);
            $table->index(['resource_id', 'starts_at', 'ends_at']);
            $table->index(['company_id', 'starts_at']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
