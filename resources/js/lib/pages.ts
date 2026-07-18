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