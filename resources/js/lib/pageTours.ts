import type { DriveStep } from 'driver.js';
import type { DashboardPage } from './pages';

type StepBuilder = (t: (key: string) => string) => DriveStep[];

export const pageTourSteps: Partial<Record<DashboardPage, StepBuilder>> = {
    overview: (t) => [
        { popover: { title: t('pageTour.overview.intro.title'), description: t('pageTour.overview.intro.text') } },
        { element: '[data-tour="ov-kpis"]', popover: { title: t('pageTour.overview.kpis.title'), description: t('pageTour.overview.kpis.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="ov-charts"]', popover: { title: t('pageTour.overview.charts.title'), description: t('pageTour.overview.charts.text'), side: 'top', align: 'start' } },
        { element: '[data-tour="ov-recent"]', popover: { title: t('pageTour.overview.recent.title'), description: t('pageTour.overview.recent.text'), side: 'top', align: 'start' } },
        { element: '[data-tour="ov-attention"]', popover: { title: t('pageTour.overview.attention.title'), description: t('pageTour.overview.attention.text'), side: 'left', align: 'start' } },
    ],
    inbox: (t) => [
        { popover: { title: t('pageTour.inbox.intro.title'), description: t('pageTour.inbox.intro.text') } },
        { element: '[data-tour="inbox-queue"]', popover: { title: t('pageTour.inbox.queue.title'), description: t('pageTour.inbox.queue.text'), side: 'right', align: 'start' } },
        { element: '[data-tour="inbox-tabs"]', popover: { title: t('pageTour.inbox.tabs.title'), description: t('pageTour.inbox.tabs.text'), side: 'bottom', align: 'center' } },
        { element: '[data-tour="inbox-autoreply"]', popover: { title: t('pageTour.inbox.autoreply.title'), description: t('pageTour.inbox.autoreply.text'), side: 'bottom', align: 'center' } },
        { element: '[data-tour="inbox-info"]', popover: { title: t('pageTour.inbox.info.title'), description: t('pageTour.inbox.info.text'), side: 'bottom', align: 'end' } },
        { element: '[data-tour="inbox-expand"]', popover: { title: t('pageTour.inbox.expand.title'), description: t('pageTour.inbox.expand.text'), side: 'left', align: 'start' } },
    ],
    leads: (t) => [
        { popover: { title: t('pageTour.leads.intro.title'), description: t('pageTour.leads.intro.text') } },
        { element: '[data-tour="leads-toggle"]', popover: { title: t('pageTour.leads.toggle.title'), description: t('pageTour.leads.toggle.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="leads-search"]', popover: { title: t('pageTour.leads.search.title'), description: t('pageTour.leads.search.text'), side: 'bottom', align: 'end' } },
        { element: '[data-tour="leads-create"]', popover: { title: t('pageTour.leads.create.title'), description: t('pageTour.leads.create.text'), side: 'bottom', align: 'end' } },
        { element: '[data-tour="leads-board"]', popover: { title: t('pageTour.leads.board.title'), description: t('pageTour.leads.board.text'), side: 'top', align: 'start' } },
    ],
    contacts: (t) => [
        { popover: { title: t('pageTour.contacts.intro.title'), description: t('pageTour.contacts.intro.text') } },
        { element: '[data-tour="contacts-search"]', popover: { title: t('pageTour.contacts.search.title'), description: t('pageTour.contacts.search.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="contacts-add"]', popover: { title: t('pageTour.contacts.add.title'), description: t('pageTour.contacts.add.text'), side: 'bottom', align: 'end' } },
        { element: '[data-tour="contacts-table"]', popover: { title: t('pageTour.contacts.table.title'), description: t('pageTour.contacts.table.text'), side: 'top', align: 'start' } },
    ],
    customers: (t) => [
        { popover: { title: t('pageTour.customers.intro.title'), description: t('pageTour.customers.intro.text') } },
        { element: '[data-tour="customer-summary"]', popover: { title: t('pageTour.customers.summary.title'), description: t('pageTour.customers.summary.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="customer-timeline"]', popover: { title: t('pageTour.customers.timeline.title'), description: t('pageTour.customers.timeline.text'), side: 'top', align: 'start' } },
    ],
    ai: (t) => [
        { popover: { title: t('pageTour.ai.intro.title'), description: t('pageTour.ai.intro.text') } },
        { element: '[data-tour="ai-tabs"]', popover: { title: t('pageTour.ai.tabs.title'), description: t('pageTour.ai.tabs.text'), side: 'bottom', align: 'end' } },
        { element: '[data-tour="ai-agent-columns"]', popover: { title: t('pageTour.ai.columns.title'), description: t('pageTour.ai.columns.text'), side: 'top', align: 'start' } },
    ],
    knowledge: (t) => [
        { popover: { title: t('pageTour.knowledge.intro.title'), description: t('pageTour.knowledge.intro.text') } },
        { element: '[data-tour="kb-stats"]', popover: { title: t('pageTour.knowledge.stats.title'), description: t('pageTour.knowledge.stats.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="kb-add"]', popover: { title: t('pageTour.knowledge.add.title'), description: t('pageTour.knowledge.add.text'), side: 'top', align: 'start' } },
        { element: '[data-tour="kb-sources"]', popover: { title: t('pageTour.knowledge.sources.title'), description: t('pageTour.knowledge.sources.text'), side: 'top', align: 'start' } },
    ],
    analytics: (t) => [
        { popover: { title: t('pageTour.analytics.intro.title'), description: t('pageTour.analytics.intro.text') } },
        { element: '[data-tour="analytics-kpis"]', popover: { title: t('pageTour.analytics.kpis.title'), description: t('pageTour.analytics.kpis.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="analytics-charts"]', popover: { title: t('pageTour.analytics.charts.title'), description: t('pageTour.analytics.charts.text'), side: 'top', align: 'start' } },
        { element: '[data-tour="analytics-heatmap"]', popover: { title: t('pageTour.analytics.heatmap.title'), description: t('pageTour.analytics.heatmap.text'), side: 'top', align: 'start' } },
        { element: '[data-tour="analytics-export"]', popover: { title: t('pageTour.analytics.export.title'), description: t('pageTour.analytics.export.text'), side: 'left', align: 'start' } },
    ],
    integrations: (t) => [
        { popover: { title: t('pageTour.integrations.intro.title'), description: t('pageTour.integrations.intro.text') } },
        { element: '[data-tour="channels-grid"]', popover: { title: t('pageTour.integrations.grid.title'), description: t('pageTour.integrations.grid.text'), side: 'top', align: 'start' } },
    ],
    marketplace: (t) => [
        { popover: { title: t('pageTour.marketplace.intro.title'), description: t('pageTour.marketplace.intro.text') } },
    ],
    team: (t) => [
        { popover: { title: t('pageTour.team.intro.title'), description: t('pageTour.team.intro.text') } },
        { element: '[data-tour="team-stats"]', popover: { title: t('pageTour.team.stats.title'), description: t('pageTour.team.stats.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="team-permissions"]', popover: { title: t('pageTour.team.permissions.title'), description: t('pageTour.team.permissions.text'), side: 'top', align: 'start' } },
        { element: '[data-tour="team-invite"]', popover: { title: t('pageTour.team.invite.title'), description: t('pageTour.team.invite.text'), side: 'bottom', align: 'end' } },
        { element: '[data-tour="team-table"]', popover: { title: t('pageTour.team.table.title'), description: t('pageTour.team.table.text'), side: 'top', align: 'start' } },
    ],
    billing: (t) => [
        { popover: { title: t('pageTour.billing.intro.title'), description: t('pageTour.billing.intro.text') } },
        { element: '[data-tour="billing-current"]', popover: { title: t('pageTour.billing.current.title'), description: t('pageTour.billing.current.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="billing-plans"]', popover: { title: t('pageTour.billing.plans.title'), description: t('pageTour.billing.plans.text'), side: 'top', align: 'start' } },
    ],
    settings: (t) => [
        { popover: { title: t('pageTour.settings.intro.title'), description: t('pageTour.settings.intro.text') } },
        { element: '[data-tour="settings-company"]', popover: { title: t('pageTour.settings.company.title'), description: t('pageTour.settings.company.text'), side: 'right', align: 'start' } },
        { element: '[data-tour="settings-notify"]', popover: { title: t('pageTour.settings.notify.title'), description: t('pageTour.settings.notify.text'), side: 'left', align: 'start' } },
    ],
    profile: (t) => [
        { popover: { title: t('pageTour.profile.intro.title'), description: t('pageTour.profile.intro.text') } },
        { element: '[data-tour="profile-form"]', popover: { title: t('pageTour.profile.form.title'), description: t('pageTour.profile.form.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="profile-repeat-tour"]', popover: { title: t('pageTour.profile.repeat.title'), description: t('pageTour.profile.repeat.text'), side: 'bottom', align: 'start' } },
    ],
};
