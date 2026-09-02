<?php

namespace App\Policies;

use App\Models\TourBooking;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class TourBookingPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canUseTenant($user);
    }

    public function view(User $user, TourBooking $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canUseTenant($user) && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    public function update(User $user, TourBooking $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    private function canUseTenant(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === app(TenantContext::class)->id();
    }
}
