<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Per-tenant editable emergency config (ЭТАП 16.8/16.9): fallback text shown to
 * customers per language while AI is down, and the internal Telegram chat that
 * receives staff alerts. Stored in tenants.settings.emergency.* — same JSON blob
 * and lockForUpdate read-modify-write pattern as IntegrationSettingsController::
 * update(), just without secret encryption (neither field is a credential).
 */
class EmergencySettingsController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        $tenant = $this->tenant($context);
        Gate::authorize('view', $tenant);

        return response()->json($this->payload($tenant));
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $this->tenant($context);
        Gate::authorize('update', $tenant);

        $data = $request->validate([
            'fallback_message.ru' => ['nullable', 'string', 'max:500'],
            'fallback_message.tj' => ['nullable', 'string', 'max:500'],
            'fallback_message.en' => ['nullable', 'string', 'max:500'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
        ]);

        DB::transaction(function () use ($tenant, $data): void {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $settings = $locked->settings ?? [];

            foreach (['ru', 'tj', 'en'] as $lang) {
                if (array_key_exists($lang, $data['fallback_message'] ?? [])) {
                    Arr::set($settings, 'emergency.fallback_message.'.$lang, $data['fallback_message'][$lang]);
                }
            }

            if (array_key_exists('telegram_chat_id', $data)) {
                Arr::set($settings, 'emergency.telegram_chat_id', $data['telegram_chat_id']);
            }

            $locked->forceFill(['settings' => $settings])->save();
        });

        return response()->json($this->payload($tenant->fresh()));
    }

    private function payload(Tenant $tenant): array
    {
        $settings = $tenant->settings ?? [];

        return [
            'fallback_message' => [
                'ru' => Arr::get($settings, 'emergency.fallback_message.ru', ''),
                'tj' => Arr::get($settings, 'emergency.fallback_message.tj', ''),
                'en' => Arr::get($settings, 'emergency.fallback_message.en', ''),
            ],
            'telegram_chat_id' => Arr::get($settings, 'emergency.telegram_chat_id', ''),
        ];
    }

    private function tenant(TenantContext $context): Tenant
    {
        return Tenant::query()->findOrFail($context->id());
    }
}
