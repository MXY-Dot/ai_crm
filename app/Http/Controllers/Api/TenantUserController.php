<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TenantUserController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $this->authorizeManageUsers($context);

        return response()->json(User::query()
            ->where('tenant_id', $context->id())
            ->latest()
            ->get(['id', 'name', 'email', 'phone', 'role', 'status', 'last_login_at', 'employee_id']));
    }

    public function store(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->authorizeManageUsers($context);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users')],
            'phone' => ['nullable', 'string', 'max:80'],
            'role' => ['required', Rule::in(User::ROLES)],
            'status' => ['nullable', Rule::in(['active', 'invited', 'disabled'])],
            'password' => ['nullable', 'string', 'min:8', 'max:120'],
            // ТЗ раздел 21 -- only meaningful for role=specialist, but accepted
            // regardless of role so an owner can pre-link it before switching roles.
            'employee_id' => ['nullable', 'integer'],
        ]);

        $employeeId = $this->resolveEmployeeId($context, $data['employee_id'] ?? null);

        $user = User::query()->create([
            'tenant_id' => $context->id(),
            'employee_id' => $employeeId,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'status' => $data['status'] ?? 'invited',
            'password' => Hash::make($data['password'] ?? str()->password(16)),
        ]);

        $audit->record('tenant_user.created', $user, $this->auditUser($user), [], $request);

        return response()->json($this->resource($user), 201);
    }

    public function update(Request $request, TenantContext $context, string $id, AuditLogger $audit): JsonResponse
    {
        $this->authorizeManageUsers($context);

        $user = User::query()->where('tenant_id', $context->id())->findOrFail($id);
        $oldAudit = $this->auditUser($user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'email' => ['sometimes', 'email', 'max:160', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:80'],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'status' => ['sometimes', Rule::in(['active', 'invited', 'disabled'])],
            'password' => ['nullable', 'string', 'min:8', 'max:120'],
            'employee_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (array_key_exists('employee_id', $data)) {
            $data['employee_id'] = $this->resolveEmployeeId($context, $data['employee_id']);
        }

        if (array_key_exists('password', $data) && $data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);
        $user = $user->refresh();

        $audit->record('tenant_user.updated', $user, $this->auditUser($user), $oldAudit, $request);

        return response()->json($this->resource($user));
    }

    /** Never trust a raw employee_id across tenants -- resolves it against this tenant's own employees or rejects it outright (404, same as any other cross-tenant lookup in this app). */
    private function resolveEmployeeId(TenantContext $context, ?int $employeeId): ?int
    {
        if ($employeeId === null) {
            return null;
        }

        return Employee::withoutGlobalScopes()->where('tenant_id', $context->id())->findOrFail($employeeId)->id;
    }

    private function authorizeManageUsers(TenantContext $context): void
    {
        abort_unless(auth()->check(), 403);

        if (auth()->user()->isSuperAdmin()) {
            return;
        }

        abort_unless(auth()->user()->tenant_id === $context->id(), 403);
        abort_unless(in_array(auth()->user()->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true), 403);
    }

    private function resource(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'phone', 'role', 'status', 'last_login_at', 'employee_id']);
    }

    private function auditUser(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'phone', 'role', 'status', 'employee_id']);
    }
}