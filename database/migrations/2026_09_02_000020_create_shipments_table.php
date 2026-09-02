<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Globally unique (not scoped to tenant_id) -- see Shipment's own docblock:
            // the public tracking lookup resolves a shipment by this alone, no tenant
            // context available or needed, same as a real courier's tracking number.
            $table->string('tracking_number')->unique();
            $table->string('sender_name');
            $table->string('sender_phone');
            $table->string('recipient_name');
            $table->string('recipient_phone');
            $table->string('origin_address')->nullable();
            $table->string('destination_address')->nullable();
            $table->string('service_type')->default('standard');
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status')->default('received');
            $table->dateTime('estimated_delivery_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
