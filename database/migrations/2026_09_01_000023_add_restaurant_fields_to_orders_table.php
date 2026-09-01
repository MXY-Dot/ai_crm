<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_type')->default('delivery')->after('status');
            $table->foreignId('table_reservation_id')->nullable()->after('order_type')
                ->constrained('table_reservations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('table_reservation_id');
            $table->dropColumn('order_type');
        });
    }
};
