<?php

namespace App\Support\Authorization;

use App\Models\User;

/**
 * Single source of truth for which dashboard pages a role can reach.
 * Mirrored in resources/js/lib/permissions.ts — keep both in sync.
 */
class RolePages
{
    /** Owner (and super_admin) only. */
    public const OWNER_ONLY = ['billing', 'settings'];

    /** Owner + manager (and super_admin). Operators are blocked. */
    public const MANAGER_PLUS = ['ai', 'knowledge', 'analytics', 'integrations', 'marketplace', 'team', 'vip', 'campaigns'];

    public static function allowed(?string $role, string $page): bool
    {
        if (! $role) {
            return false;
        }

        if (in_array($role, [User::ROLE_SUPER_ADMIN, User::ROLE_OWNER], true)) {
            return true;
        }

        if (in_array($page, self::OWNER_ONLY, true)) {
            return false;
        }

        if ($role === User::ROLE_MANAGER) {
            return true;
        }

        return ! in_array($page, self::MANAGER_PLUS, true);
    }
}
