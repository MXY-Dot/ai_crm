export type DashboardPage = 'overview' | 'inbox' | 'leads' | 'customers' | 'contacts' | 'vip' | 'campaigns' | 'ai' | 'knowledge' | 'analytics' | 'integrations' | 'marketplace' | 'team' | 'support' | 'billing' | 'settings' | 'profile' | 'booking' | 'booking-settings' | 'orders' | 'catalog-settings' | 'notifications';

export const pagePaths: Record<DashboardPage, string> = {
    overview: '/app',
    inbox: '/inbox',
    leads: '/leads',
    customers: '/customers',
    contacts: '/contacts',
    vip: '/vip',
    campaigns: '/campaigns',
    ai: '/ai',
    knowledge: '/knowledge',
    analytics: '/analytics',
    integrations: '/integrations',
    marketplace: '/marketplace',
    team: '/team',
    support: '/support',
    billing: '/billing',
    settings: '/settings',
    profile: '/profile',
    booking: '/booking',
    'booking-settings': '/booking-settings',
    orders: '/orders',
    'catalog-settings': '/catalog-settings',
    notifications: '/notifications',
};

export function pathForRecord(kind: 'lead' | 'customer' | 'conversation'): string {
    return { lead: pagePaths.leads, customer: pagePaths.customers, conversation: pagePaths.inbox }[kind];
}

export function pageFromPath(pathname: string): DashboardPage {
    return (Object.entries(pagePaths).find(([, path]) => path === pathname)?.[0]
        ?? 'overview') as DashboardPage
}
