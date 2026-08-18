<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SuperAdminCompanyController extends Controller
{
    private const PLANS = [
        'starter' => ['price' => 29, 'conversations_limit' => 500, 'ai_agents_limit' => 1],
        'pro' => ['price' => 99, 'conversations_limit' => null, 'ai_agents_limit' => 5],
        'business' => ['price' => 249, 'conversations_limit' => null, 'ai_agents_limit' => null],
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->with(['companies' => fn ($q) => $q->limit(1)])->withCount('users');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('companies', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('users', fn ($u) => $u->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($plan = $request->query('plan')) {
            $query->where('settings->billing->plan', $plan);
        }

        $tenants = $query->latest()->paginate(15)->withQueryString();

        $tenantIds = collect($tenants->items())->pluck('id');

        $owners = User::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('role', User::ROLE_OWNER)
            ->get(['id', 'tenant_id', 'name', 'email', 'avatar_path'])
            ->keyBy('tenant_id');

        $channelCounts = Channel::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->selectRaw('tenant_id, count(*) as count')
            ->groupBy('tenant_id')
            ->pluck('count', 'tenant_id');

        $items = collect($tenants->items())->map(function (Tenant $tenant) use ($owners, $channelCounts) {
            $company = $tenant->companies->first();
            $owner = $owners->get($tenant->id);

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at,
                'trial_ends_at' => $tenant->trial_ends_at,
                'plan' => Arr::get($tenant->settings, 'billing.plan', 'starter'),
                'users_count' => $tenant->users_count,
                'channels_count' => (int) ($channelCounts[$tenant->id] ?? 0),
                'company' => $company ? [
                    'id' => $company->id,
                    'name' => $company->name,
                    'industry' => $company->industry,
                    'logo_url' => $company->logo_url,
                ] : null,
                'owner' => $owner ? [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'avatar_url' => $owner->avatar_url,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
                'total' => $tenants->total(),
            ],
        ]);
    }

    public function lookup(): JsonResponse
    {
        $tenants = Tenant::query()->with(['companies' => fn ($q) => $q->limit(1)])->orderBy('name')->limit(500)->get(['id', 'name']);

        return response()->json($tenants->map(fn (Tenant $tenant) => [
            'id' => $tenant->id,
            'name' => $tenant->companies->first()?->name ?? $tenant->name,
        ]));
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $owner = User::query()->where('tenant_id', $tenant->id)->where('role', User::ROLE_OWNER)->first();
        $team = User::query()->where('tenant_id', $tenant->id)->orderByDesc('created_at')
            ->get(['id', 'name', 'email', 'role', 'status', 'avatar_path', 'last_login_at']);

        $plan = Arr::get($tenant->settings, 'billing.plan', 'starter');
        $planMeta = self::PLANS[$plan] ?? self::PLANS['starter'];

        $conversations30d = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        $messages30d = Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('sent_at', '>=', now()->subDays(30))
            ->count();
        $leadsCount = Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $aiAgentsCount = AiAgent::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $channels = Channel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get(['id', 'provider', 'name', 'status', 'last_synced_at']);
        $mrr = $tenant->status === 'active' ? $planMeta['price'] : 0;

        $activity = AuditLog::withoutGlobalScopes()
            ->with('user:id,name')
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->limit(20)
            ->get(['id', 'user_id', 'action', 'entity_type', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'entity_type' => $log->entity_type ? class_basename($log->entity_type) : null,
                'user' => $log->user?->name,
                'created_at' => $log->created_at,
            ]);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'plan' => $plan,
                'created_at' => $tenant->created_at,
                'trial_ends_at' => $tenant->trial_ends_at,
            ],
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'industry' => $company->industry,
                'phone' => $company->phone,
                'email' => $company->email,
                'logo_url' => $company->logo_url,
            ] : null,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'avatar_url' => $owner->avatar_url,
            ] : null,
            'kpis' => [
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'team_count' => $team->count(),
                'messages_30d' => $messages30d,
                'leads_count' => $leadsCount,
                'active_channels' => $channels->where('status', 'active')->count(),
            ],
            'limits' => [
                'conversations' => ['used' => $conversations30d, 'limit' => $planMeta['conversations_limit']],
                'ai_agents' => ['used' => $aiAgentsCount, 'limit' => $planMeta['ai_agents_limit']],
            ],
            'channels' => $channels->map(fn (Channel $c) => [
                'id' => $c->id,
                'provider' => $c->provider,
                'name' => $c->name,
                'status' => $c->status,
                'last_synced_at' => $c->last_synced_at,
            ]),
            'team' => $team->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'status' => $u->status,
                'avatar_url' => $u->avatar_url,
                'last_login_at' => $u->last_login_at,
            ]),
            'activity' => $activity,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:120'],
            'plan' => ['nullable', Rule::in(['starter', 'pro', 'business'])],
            'status' => ['nullable', Rule::in(['trial', 'active', 'paused', 'blocked'])],
            'owner_name' => ['required', 'string', 'max:160'],
            'owner_email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'owner_password' => ['nullable', 'string', Password::min(8)],
        ]);

        $status = $data['status'] ?? 'trial';

        $tenant = DB::transaction(function () use ($data, $status) {
            $baseSlug = Str::slug($data['company_name']) ?: 'company';
            $slug = $baseSlug;
            for ($suffix = 1; Tenant::query()->where('slug', $slug)->exists(); $suffix++) {
                $slug = "{$baseSlug}-{$suffix}";
            }

            $tenant = Tenant::query()->create([
                'name' => $data['company_name'],
                'slug' => $slug,
                'status' => $status,
                'trial_ends_at' => $status === 'trial' ? now()->addDays(14) : null,
                'settings' => ['billing' => ['plan' => $data['plan'] ?? 'starter']],
            ]);

            Company::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['company_name'],
                'industry' => $data['industry'] ?? 'service',
            ]);

            User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'] ?? str()->password(16),
                'role' => User::ROLE_OWNER,
                'status' => 'active',
            ]);

            return $tenant;
        });

        $audit->record('tenant.created', $tenant, ['name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status], [], $request, tenantId: $tenant->id);

        return response()->json($tenant->fresh(), 201);
    }

    public function destroy(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $expectedName = $company?->name ?? $tenant->name;

        $data = $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        abort_unless($data['confirm_name'] === $expectedName, 422, 'Название компании не совпадает. Удаление отменено.');

        $tenantId = $tenant->id;
        $tenantName = $tenant->name;

        DB::transaction(function () use ($tenant) {
            User::query()->where('tenant_id', $tenant->id)->delete();
            $tenant->delete();
        });

        // ЭТАП 10.4 — the single most irreversible Super Admin action in the
        // app (whole tenant + users, permanently) was entirely unaudited before.
        $audit->record('tenant.deleted', Tenant::class, [], ['id' => $tenantId, 'name' => $tenantName], $request, tenantId: $tenantId);

        return response()->json(['message' => 'Компания и все связанные данные удалены']);
    }

    public function updateStatus(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['trial', 'active', 'paused', 'blocked'])],
        ]);

        $previousStatus = $tenant->status;
        $tenant->update(['status' => $data['status']]);
        $audit->record('tenant.status_updated', $tenant, ['status' => $data['status']], ['status' => $previousStatus], $request, tenantId: $tenant->id);

        return response()->json($tenant->fresh());
    }

    public function updatePlan(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['starter', 'pro', 'business'])],
        ]);

        // `settings` is a single JSON blob multiple controllers read-modify-write
        // independently (see IntegrationSettingsController::update()) — without a
        // lock, two concurrent requests can both read the same "before" snapshot and
        // the second save silently clobbers whatever the first one added (e.g. a
        // Telegram token saved between this request's read and its write vanishes).
        // lockForUpdate() inside a transaction serializes concurrent writers so the
        // merge always starts from the latest committed data.
        $previousPlan = Arr::get($tenant->settings ?? [], 'billing.plan');

        DB::transaction(function () use ($tenant, $data) {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $settings = $locked->settings ?? [];
            Arr::set($settings, 'billing.plan', $data['plan']);
            $locked->update(['settings' => $settings]);
        });

        $audit->record('tenant.plan_updated', $tenant, ['plan' => $data['plan']], ['plan' => $previousPlan], $request, tenantId: $tenant->id);

        return response()->json($tenant->fresh());
    }
}
