<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Campaigns moved to super_admin-only. TenantResourcePolicy::before() already
 * grants super_admin unconditionally, so these overrides only run for everyone
 * else (owner/manager/operator) and simply deny — access now goes through the
 * admin-scoped /api/admin/companies/{tenant}/campaigns routes instead.
 */
class CampaignPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Model $record): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
