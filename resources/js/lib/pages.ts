export type DashboardPage = 'overview' | 'inbox' | 'leads' | 'customers' | 'contacts' | 'ai' | 'knowledge' | 'analytics' | 'integrations' | 'marketplace' | 'team' | 'billing' | 'settings' | 'profile';

export const pagePaths: Record<DashboardPage, string> = {
    overview: '/app',
    inbox: '/inbox',
    leads: '/leads',
    customers: '/customers',
    contacts: '/contacts',
    ai: '/ai',
    knowledge: '/knowledge',
    analytics: '/analytics',
    integrations: '/integrations',
    marketplace: '/marketplace',
    team: '/team',
    billing: '/billing',
    settings: '/settings',
    profile: '/profile',
};

export function pathForRecord(kind: 'lead' | 'customer' | 'conversation'): string {
    return { lead: pagePaths.leads, customer: pagePaths.customers, conversation: pagePaths.inbox }[kind];
}

export function pageFromPath(pathname: string): DashboardPage {
    return (Object.entries(pagePaths).find(([, path]) => path === pathname)?.[0]
        ?? 'overview') as DashboardPage
}
