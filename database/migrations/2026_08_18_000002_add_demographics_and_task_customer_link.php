<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('city', 120)->nullable()->after('is_business');
            $table->unsignedSmallInteger('birth_year')->nullable()->after('city');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['city', 'birth_year']);
        });
    }
};
