<?php

namespace App\Support\Integrations;

use App\Models\Tenant;

/**
 * Resolves which tenant owns an incoming Meta webhook event. Telegram/Chatwoot
 * webhooks carry a ?tenant_slug= in their per-tenant URL; Meta's webhook is one
 * shared platform-level URL, so the tenant has to be looked up from the
 * Page/WABA/Instagram id embedded in the payload itself, against whatever each
 * tenant saved via IntegrationSettingsController::update() (those ids are
 * stored in plain — only the access tokens themselves are encrypted).
 */
class MetaChannelResolver
{
    public function byFacebookPageId(string $pageId): ?Tenant
    {
        return $pageId === '' ? null : Tenant::query()->where('settings->integrations->facebook->page_id', $pageId)->first();
    }

    public function byInstagramBusinessAccountId(string $accountId): ?Tenant
    {
        return $accountId === '' ? null : Tenant::query()->where('settings->integrations->instagram->business_account_id', $accountId)->first();
    }

    public function byWhatsappPhoneNumberId(string $phoneNumberId): ?Tenant
    {
        return $phoneNumberId === '' ? null : Tenant::query()->where('settings->integrations->whatsapp->phone_number_id', $phoneNumberId)->first();
    }
}
