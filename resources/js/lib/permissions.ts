import type { DashboardPage } from './pages';

export type Role = 'super_admin' | 'owner' | 'manager' | 'operator';

/**
 * Single source of truth for which dashboard pages a role can reach.
 * Mirrored in app/Support/Authorization/RolePages.php — keep both in sync.
 */
const OWNER_ONLY: DashboardPage[] = ['billing', 'settings'];
const MANAGER_PLUS: DashboardPage[] = ['ai', 'knowledge', 'analytics', 'integrations', 'marketplace', 'team', 'vip', 'campaigns'];

export function canAccessPage(role: Role | string | undefined | null, page: DashboardPage): boolean {
    if (! role) return false;
    if (role === 'super_admin' || role === 'owner') return true;
    if (OWNER_ONLY.includes(page)) return false;
    if (role === 'manager') return true;

    return ! MANAGER_PLUS.includes(page);
}
