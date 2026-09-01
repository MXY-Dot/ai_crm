<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_gateway_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->string('external_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('TJS');
            $table->string('status')->default('pending');
            $table->string('checkout_url')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
            $table->index(['gateway', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_gateway_payments');
    }
};
