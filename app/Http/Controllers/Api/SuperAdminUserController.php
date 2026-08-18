<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SuperAdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $users = $query->latest()->paginate(20)->withQueryString();

        $tenantIds = collect($users->items())->pluck('tenant_id')->filter()->unique();

        $tenants = Tenant::query()->whereIn('id', $tenantIds)->get(['id', 'name'])->keyBy('id');
        $companies = Company::withoutGlobalScopes()->whereIn('tenant_id', $tenantIds)->get(['tenant_id', 'name'])->keyBy('tenant_id');

        $userIds = collect($users->items())->pluck('id');
        $lastSessions = DB::table('sessions')
            ->whereIn('user_id', $userIds)
            ->whereNotNull('ip_address')
            ->orderByDesc('last_activity')
            ->get(['user_id', 'ip_address'])
            ->unique('user_id')
            ->keyBy('user_id');

        $items = collect($users->items())->map(function (User $user) use ($tenants, $companies, $lastSessions) {
            $tenant = $user->tenant_id ? $tenants->get($user->tenant_id) : null;
            $company = $user->tenant_id ? $companies->get($user->tenant_id) : null;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'role' => $user->role,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'last_ip' => $lastSessions->get($user->id)?->ip_address,
                'created_at' => $user->created_at,
                'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $company?->name ?? $tenant->name] : null,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $tenant = $user->tenant_id ? Tenant::query()->find($user->tenant_id) : null;
        $company = $user->tenant_id ? Company::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->first() : null;

        $lastIp = DB::table('sessions')->where('user_id', $user->id)->whereNotNull('ip_address')->orderByDesc('last_activity')->value('ip_address');
        $sessionsCount = DB::table('sessions')->where('user_id', $user->id)->count();

        $assignedConversations = Conversation::withoutGlobalScopes()->where('assigned_user_id', $user->id)->count();
        $openConversations = Conversation::withoutGlobalScopes()->where('assigned_user_id', $user->id)->where('status', 'open')->count();
        $assignedLeads = Lead::withoutGlobalScopes()->where('assigned_user_id', $user->id)->count();

        $activity = AuditLog::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(20)
            ->get(['id', 'action', 'entity_type', 'ip_address', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'entity_type' => $log->entity_type ? class_basename($log->entity_type) : null,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
            ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'role' => $user->role,
                'status' => $user->status,
                'two_factor_enabled' => $user->two_factor_enabled,
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
                'last_ip' => $lastIp,
                'sessions_count' => $sessionsCount,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $company?->name ?? $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
            ] : null,
            'stats' => [
                'assigned_conversations' => $assignedConversations,
                'open_conversations' => $openConversations,
                'assigned_leads' => $assignedLeads,
            ],
            'activity' => $activity,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users')],
            'role' => ['required', Rule::in([User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR])],
            'password' => ['nullable', 'string', 'min:8', 'max:120'],
        ]);

        $user = User::query()->create([
            'tenant_id' => $data['tenant_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'status' => 'invited',
            'password' => Hash::make($data['password'] ?? str()->password(16)),
        ]);

        $audit->record('user.created', $user, ['name' => $user->name, 'email' => $user->email, 'role' => $user->role], [], $request, tenantId: $user->tenant_id);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'status']), 201);
    }

    public function updateStatus(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        abort_if($user->id === $request->user()?->id, 422, 'Нельзя изменить статус собственной учётной записи');

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'invited', 'disabled'])],
        ]);

        $previousStatus = $user->status;
        $user->update(['status' => $data['status']]);
        $audit->record('user.status_updated', $user, ['status' => $data['status']], ['status' => $previousStatus], $request, tenantId: $user->tenant_id);

        return response()->json($user->refresh()->only(['id', 'status']));
    }

    public function resetPassword(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $password = str()->password(16);
        $user->update(['password' => Hash::make($password)]);
        // Deliberately never logs the password itself — only that a reset happened.
        $audit->record('user.password_reset', $user, [], [], $request, tenantId: $user->tenant_id);

        return response()->json(['password' => $password]);
    }
}
