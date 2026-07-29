export type DashboardPage = 'overview' | 'inbox' | 'leads' | 'customers' | 'crm' | 'ai' | 'knowledge' | 'analytics' | 'integrations' | 'settings';

export const pagePaths: Record<DashboardPage, string> = {
    overview: '/app',
    inbox: '/inbox',
    leads: '/leads',
    customers: '/customers',
    crm: '/crm',
    ai: '/ai',
    knowledge: '/knowledge',
    analytics: '/analytics',
    integrations: '/integrations',
    settings: '/settings',
};

export function pathForRecord(kind: 'lead' | 'customer' | 'conversation'): string {
    return { lead: pagePaths.leads, customer: pagePaths.customers, conversation: pagePaths.inbox }[kind];
}

export function pageFromPath(pathname: string): DashboardPage {
    return (Object.entries(pagePaths).find(([, path]) => path === pathname)?.[0]
        ?? 'overview') as DashboardPage
}
