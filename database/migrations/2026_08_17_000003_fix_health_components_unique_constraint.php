<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plain unique(component, tenant_id) constraint never catches duplicates among
 * platform-wide rows (tenant_id IS NULL) — Postgres treats every NULL as distinct
 * for uniqueness purposes, so concurrent queue workers / the scheduled probe
 * racing HealthComponent::createOrFirst() at the same instant could each insert
 * their own row for the same component. Replaced with two partial unique
 * indexes: one keyed on component alone for platform rows, one keyed on
 * (component, tenant_id) for per-tenant Dify rows, which actually enforces
 * "one row per component" in both cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_components', function (Blueprint $table): void {
            $table->dropUnique(['component', 'tenant_id']);
        });

        // Consolidate duplicates the race already created — keep the newest row
        // per platform component, drop the rest. Pure circuit-breaker state
        // (all currently status='up', no open incidents), safe to discard losers.
        DB::statement('
            DELETE FROM health_components a USING health_components b
            WHERE a.tenant_id IS NULL AND b.tenant_id IS NULL
              AND a.component = b.component
              AND a.id < b.id
        ');

        DB::statement('CREATE UNIQUE INDEX health_components_platform_unique ON health_components (component) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX health_components_tenant_unique ON health_components (component, tenant_id) WHERE tenant_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS health_components_platform_unique');
        DB::statement('DROP INDEX IF EXISTS health_components_tenant_unique');

        Schema::table('health_components', function (Blueprint $table): void {
            $table->unique(['component', 'tenant_id']);
        });
    }
};
