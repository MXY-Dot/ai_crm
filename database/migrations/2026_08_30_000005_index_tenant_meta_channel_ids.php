<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MetaChannelResolver looks up the owning tenant for every single WhatsApp/
 * Instagram/Facebook webhook by a JSON path on tenants.settings (Meta's shared
 * platform-level webhook URL means every tenant's events land on the same
 * endpoint and have to be routed by payload, not by URL — see that class's own
 * docblock). Without an index, each of those three lookups was a full
 * sequential scan of the tenants table on every webhook call -- invisible at
 * a handful of tenants, but linearly worse as the platform grows, and this is
 * on the hot path for a) response latency Meta expects and b) not falling
 * behind under real webhook volume. Expression indexes matching Laravel's
 * exact generated JSON-path SQL (intermediate segments via ->, the last via
 * ->>) make each lookup an index scan instead, regardless of tenant count.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX IF NOT EXISTS idx_tenants_settings_facebook_page_id ON tenants ((settings->'integrations'->'facebook'->>'page_id'))");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_tenants_settings_instagram_business_account_id ON tenants ((settings->'integrations'->'instagram'->>'business_account_id'))");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_tenants_settings_whatsapp_phone_number_id ON tenants ((settings->'integrations'->'whatsapp'->>'phone_number_id'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_tenants_settings_facebook_page_id');
        DB::statement('DROP INDEX IF EXISTS idx_tenants_settings_instagram_business_account_id');
        DB::statement('DROP INDEX IF EXISTS idx_tenants_settings_whatsapp_phone_number_id');
    }
};
