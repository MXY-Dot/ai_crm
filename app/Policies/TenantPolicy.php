<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->tenant_id === $tenant->id;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->tenant_id === $tenant->id && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true);
    }
}
