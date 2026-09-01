<?php

namespace App\Policies;

use App\Models\RoomReservation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * ТЗ раздел 21 -- same reasoning as BookingPolicy/TableReservationPolicy: an
 * operator must be able to create/manage reservations, not just owner/manager.
 * No specialist-only scoping (no employee concept here), but DOES need
 * managePayments() (unlike TableReservationPolicy) since a room reservation
 * carries its own real money, same as a Booking.
 */
class RoomReservationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canUseTenant($user);
    }

    public function view(User $user, RoomReservation $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canUseTenant($user) && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    public function update(User $user, RoomReservation $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    /** Same accountant-specific ability as BookingPolicy::managePayments(). */
    public function managePayments(User $user, RoomReservation $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR, User::ROLE_ACCOUNTANT], true);
    }

    private function canUseTenant(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === app(TenantContext::class)->id();
    }
}
