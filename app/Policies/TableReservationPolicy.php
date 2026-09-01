<?php

namespace App\Policies;

use App\Models\TableReservation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * ТЗ раздел 21 -- same reasoning as BookingPolicy: an operator must be able
 * to create/manage reservations, not just owner/manager. No specialist-only
 * scoping here (unlike BookingPolicy::view()/update()) since a table
 * reservation has no employee/specialist concept to scope by.
 */
class TableReservationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canUseTenant($user);
    }

    public function view(User $user, TableReservation $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canUseTenant($user) && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    public function update(User $user, TableReservation $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    private function canUseTenant(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === app(TenantContext::class)->id();
    }
}
