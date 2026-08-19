<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\WidgetToken;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A tenant can embed the widget from several sites/pages, each with its own
 * named token — all resolve to the same tenant's single `provider = website`
 * Channel (see WidgetController::channel()), so conversations/leads still
 * funnel into one place regardless of which token a visitor's page used.
 */
class WidgetTokenController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);

        $tokens = WidgetToken::query()->latest()->get();

        return response()->json(['data' => $tokens->map(fn (WidgetToken $token) => $this->payload($token))]);
    }

    public function store(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
        ]);

        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $token = WidgetToken::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company?->id,
            'label' => $data['label'],
            'token' => Str::random(24),
        ]);

        $audit->record('widget_token.created', $token, ['label' => $token->label], [], $request);

        return response()->json($this->payload($token), 201);
    }

    public function destroy(Request $request, WidgetToken $widgetToken, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        abort_unless((int) $widgetToken->tenant_id === (int) $tenant->id, 404);

        $audit->record('widget_token.deleted', WidgetToken::class, [], ['id' => $widgetToken->id, 'label' => $widgetToken->label], $request, tenantId: $tenant->id);

        $widgetToken->delete();

        return response()->json(['message' => 'Токен удалён']);
    }

    private function payload(WidgetToken $token): array
    {
        return [
            'id' => $token->id,
            'label' => $token->label,
            'token' => $token->token,
            'embed_snippet' => '<script src="'.rtrim(config('app.url'), '/').'/widget.js" data-site-key="'.$token->token.'" async></script>',
            'last_used_at' => $token->last_used_at,
            'created_at' => $token->created_at,
        ];
    }
}
