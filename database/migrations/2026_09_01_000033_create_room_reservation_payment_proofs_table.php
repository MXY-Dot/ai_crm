<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_reservation_payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_reservation_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('operation_number')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['operation_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_reservation_payment_proofs');
    }
};
