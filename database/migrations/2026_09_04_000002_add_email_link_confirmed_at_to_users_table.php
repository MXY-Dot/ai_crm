<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_link_confirmed_at')->nullable()->after('email_verification_code_expires_at');
        });

        // Same grandfathering as the previous migration -- everyone who could
        // already log in before this two-tier gate existed shouldn't suddenly
        // get stuck on the "confirm by link in Settings" screen.
        DB::table('users')->whereNotNull('email_verified_at')->update(['email_link_confirmed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_link_confirmed_at');
        });
    }
};
