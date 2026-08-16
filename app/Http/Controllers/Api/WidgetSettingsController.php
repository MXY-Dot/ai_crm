<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRM-side configuration for the website chat widget (see WidgetController
 * for the public, unauthenticated half a visitor's browser actually talks
 * to). One `provider = 'website'` Channel per tenant carries the site key
 * (`external_id`) and settings (welcome message) — auto-provisioned here on
 * first read so there's no separate manual "set up the widget" step.
 */
class WidgetSettingsController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);

        return response()->json($this->payload($this->channel($tenant)));
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        $data = $request->validate([
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['nullable', Rule::in(['right', 'left'])],
            'launcher_icon' => ['nullable', Rule::in(['chat', 'message', 'help'])],
        ]);

        $channel = $this->channel($tenant);
        $settings = $channel->settings ?? [];

        if (array_key_exists('welcome_message', $data)) {
            Arr::set($settings, 'welcome_message', $data['welcome_message']);
        }

        if (array_key_exists('color', $data)) {
            Arr::set($settings, 'widget_color', $data['color']);
        }

        if (array_key_exists('position', $data)) {
            Arr::set($settings, 'widget_position', $data['position']);
        }

        if (array_key_exists('launcher_icon', $data)) {
            Arr::set($settings, 'widget_launcher_icon', $data['launcher_icon']);
        }

        $channel->forceFill(['settings' => $settings])->save();

        return response()->json($this->payload($channel));
    }

    public function regenerateKey(TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        $channel = $this->channel($tenant);
        $channel->forceFill(['external_id' => Str::random(24)])->save();

        return response()->json($this->payload($channel));
    }

    private function channel(Tenant $tenant): Channel
    {
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $channel = Channel::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'provider' => 'website', 'name' => 'Website Widget'],
            ['company_id' => $company?->id, 'status' => 'connected']
        );

        if (! $channel->external_id) {
            $channel->forceFill(['external_id' => Str::random(24), 'status' => 'connected', 'last_synced_at' => now()])->save();
        }

        return $channel;
    }

    private function payload(Channel $channel): array
    {
        $siteKey = $channel->external_id;

        return [
            'site_key' => $siteKey,
            'status' => $channel->status,
            'welcome_message' => Arr::get($channel->settings ?? [], 'welcome_message'),
            'color' => Arr::get($channel->settings ?? [], 'widget_color', '#16a34a'),
            'position' => Arr::get($channel->settings ?? [], 'widget_position', 'right'),
            'launcher_icon' => Arr::get($channel->settings ?? [], 'widget_launcher_icon', 'chat'),
            'embed_snippet' => '<script src="'.rtrim(config('app.url'), '/').'/widget.js" data-site-key="'.$siteKey.'" async></script>',
            'last_synced_at' => $channel->last_synced_at,
        ];
    }
}
