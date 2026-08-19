<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple named embed tokens per tenant — a company with several sites/pages
 * can create a separate token for each, all routing to the same tenant's
 * single `provider = 'website'` Channel (see WidgetSettingsController's
 * firstOrCreate) so conversations/leads still funnel into one place
 * regardless of which token a visitor's page used. WidgetController resolves
 * the tenant by token, not by `channels.external_id` directly, after this
 * migration — see its updated channel() method.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 120);
            $table->string('token', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // Backfill: every tenant that already has a website Channel keeps working
        // under its existing embed snippet — that external_id becomes the first
        // ("Основной") token instead of a second, disconnected identifier.
        $channels = DB::table('channels')
            ->where('provider', 'website')
            ->whereNotNull('external_id')
            ->get(['tenant_id', 'company_id', 'external_id']);

        foreach ($channels as $channel) {
            DB::table('widget_tokens')->insert([
                'tenant_id' => $channel->tenant_id,
                'company_id' => $channel->company_id,
                'label' => 'Основной',
                'token' => $channel->external_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_tokens');
    }
};
