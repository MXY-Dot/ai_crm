<?php

namespace App\Policies;

use App\Models\RepairOrder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * ТЗ раздел 21 -- same reasoning as TableReservationPolicy/RoomReservationPolicy:
 * an operator must be able to create/manage repair jobs, not just owner/manager.
 * No managePayments() ability -- billing happens through the linked Order's own
 * endpoints/policy, not through RepairOrder directly.
 */
class RepairOrderPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canUseTenant($user);
    }

    public function view(User $user, RepairOrder $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canUseTenant($user) && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    public function update(User $user, RepairOrder $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    private function canUseTenant(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === app(TenantContext::class)->id();
    }
}
