import type { DriveStep } from 'driver.js';
import type { DashboardPage } from './pages';

type StepBuilder = (t: (key: string) => string) => DriveStep[];

export const pageTourSteps: Partial<Record<DashboardPage, StepBuilder>> = {
    overview: (t) => [
        { popover: { title: t('pageTour.overview.intro.title'), description: t('pageTour.overview.intro.text') } },
        { element: '[data-tour="ov-kpis"]', popover: { title: t('pageTour.overview.kpis.title'), description: t('pageTour.overview.kpis.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="ov-charts"]', popover: { title: t('pageTour.overview.charts.title'), description: t('pageTour.overview.charts.text'), side: 'top', align: 'start' } },
    ],
    inbox: (t) => [
        { popover: { title: t('pageTour.inbox.intro.title'), description: t('pageTour.inbox.intro.text') } },
        { element: '[data-tour="inbox-queue"]', popover: { title: t('pageTour.inbox.queue.title'), description: t('pageTour.inbox.queue.text'), side: 'right', align: 'start' } },
        { element: '[data-tour="inbox-expand"]', popover: { title: t('pageTour.inbox.expand.title'), description: t('pageTour.inbox.expand.text'), side: 'left', align: 'start' } },
    ],
    leads: (t) => [
        { popover: { title: t('pageTour.leads.intro.title'), description: t('pageTour.leads.intro.text') } },
        { element: '[data-tour="leads-toggle"]', popover: { title: t('pageTour.leads.toggle.title'), description: t('pageTour.leads.toggle.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="leads-create"]', popover: { title: t('pageTour.leads.create.title'), description: t('pageTour.leads.create.text'), side: 'bottom', align: 'end' } },
    ],
    contacts: (t) => [
        { popover: { title: t('pageTour.contacts.intro.title'), description: t('pageTour.contacts.intro.text') } },
        { element: '[data-tour="contacts-search"]', popover: { title: t('pageTour.contacts.search.title'), description: t('pageTour.contacts.search.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="contacts-add"]', popover: { title: t('pageTour.contacts.add.title'), description: t('pageTour.contacts.add.text'), side: 'bottom', align: 'end' } },
    ],
    customers: (t) => [
        { popover: { title: t('pageTour.customers.intro.title'), description: t('pageTour.customers.intro.text') } },
    ],
    ai: (t) => [
        { popover: { title: t('pageTour.ai.intro.title'), description: t('pageTour.ai.intro.text') } },
    ],
    knowledge: (t) => [
        { popover: { title: t('pageTour.knowledge.intro.title'), description: t('pageTour.knowledge.intro.text') } },
    ],
    analytics: (t) => [
        { popover: { title: t('pageTour.analytics.intro.title'), description: t('pageTour.analytics.intro.text') } },
        { element: '[data-tour="analytics-export"]', popover: { title: t('pageTour.analytics.export.title'), description: t('pageTour.analytics.export.text'), side: 'left', align: 'start' } },
    ],
    integrations: (t) => [
        { popover: { title: t('pageTour.integrations.intro.title'), description: t('pageTour.integrations.intro.text') } },
    ],
    marketplace: (t) => [
        { popover: { title: t('pageTour.marketplace.intro.title'), description: t('pageTour.marketplace.intro.text') } },
    ],
    team: (t) => [
        { popover: { title: t('pageTour.team.intro.title'), description: t('pageTour.team.intro.text') } },
        { element: '[data-tour="team-invite"]', popover: { title: t('pageTour.team.invite.title'), description: t('pageTour.team.invite.text'), side: 'bottom', align: 'end' } },
        { element: '[data-tour="team-table"]', popover: { title: t('pageTour.team.table.title'), description: t('pageTour.team.table.text'), side: 'top', align: 'start' } },
    ],
    billing: (t) => [
        { popover: { title: t('pageTour.billing.intro.title'), description: t('pageTour.billing.intro.text') } },
    ],
    settings: (t) => [
        { popover: { title: t('pageTour.settings.intro.title'), description: t('pageTour.settings.intro.text') } },
        { element: '[data-tour="settings-company"]', popover: { title: t('pageTour.settings.company.title'), description: t('pageTour.settings.company.text'), side: 'right', align: 'start' } },
        { element: '[data-tour="settings-notify"]', popover: { title: t('pageTour.settings.notify.title'), description: t('pageTour.settings.notify.text'), side: 'left', align: 'start' } },
    ],
    profile: (t) => [
        { popover: { title: t('pageTour.profile.intro.title'), description: t('pageTour.profile.intro.text') } },
    ],
};
