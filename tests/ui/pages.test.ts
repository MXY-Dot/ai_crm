import { readFileSync } from 'node:fs'
import assert from 'node:assert/strict'
import test from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import type { Bootstrap } from '../../resources/js/stores/crmDashboard'
import { pageFromPath, pathForRecord } from '../../resources/js/lib/pages'

function bootstrap(ids: number[]): Bootstrap {
    return {
        user: null,
        tenant: null,
        company: null,
        stats: {},
        customers: ids.map((id) => ({ id, name: `Customer ${id}`, phone: null, email: null, source: null })),
        leads: ids.map((id) => ({ id, customer_id: id, title: `Lead ${id}`, status: 'new', source: null, score: 0, ai_summary: null })),
        tasks: [],
        channels: [],
        conversations: ids.map((id) => ({ id, subject: `Conversation ${id}`, status: 'open', priority: 'normal', last_message_at: null, ai_summary: null })),
        messages: [],
        aiAgents: [],
        aiRuns: [],
        knowledgeDocuments: [],
        auditLogs: [],
        tenantUsers: [],
    }
}

test('maps routed URLs to dashboard page ids', () => {
    assert.equal(pageFromPath('/app'), 'overview')
    assert.equal(pageFromPath('/integrations'), 'integrations')
    assert.equal(pageFromPath('/unknown'), 'overview')
})

test('maps record kinds to routed workspaces', () => {
    assert.equal(pathForRecord('lead'), '/leads')
    assert.equal(pathForRecord('customer'), '/customers')
    assert.equal(pathForRecord('conversation'), '/inbox')
})

test('reconciles selected records during bootstrap hydration', async () => {
    globalThis.document ??= { addEventListener: () => {}, querySelector: () => null } as unknown as Document

    const { useCrmDashboardStore } = await import('../../resources/js/stores/crmDashboard')

    setActivePinia(createPinia())

    const store = useCrmDashboardStore()
    store.hydrateBootstrap(bootstrap([1, 2]))
    store.selectedLeadId = 2
    store.selectedCustomerId = 2
    store.selectedConversationId = 2

    store.hydrateBootstrap(bootstrap([1, 2]))

    assert.equal(store.selectedLeadId, 2)
    assert.equal(store.selectedCustomerId, 2)
    assert.equal(store.selectedConversationId, 2)

    store.hydrateBootstrap(bootstrap([1]))

    assert.equal(store.selectedLeadId, 1)
    assert.equal(store.selectedCustomerId, 1)
    assert.equal(store.selectedConversationId, 1)
})

test('logs out through an Inertia visit', () => {
    const layout = readFileSync(new URL('../../resources/js/layouts/AppLayout.vue', import.meta.url), 'utf8')

    assert.match(layout, /router\.post\('\/logout', \{\}, \{\s*onFinish:/)
    assert.doesNotMatch(layout, /window\.location\.assign\(/)
})

test('keeps pipeline selection consumer-owned with separate lead actions', () => {
    const pipeline = readFileSync(new URL('../../resources/js/components/dashboard/LeadPipeline.vue', import.meta.url), 'utf8')
    const leadsPage = readFileSync(new URL('../../resources/js/pages/LeadsPage.vue', import.meta.url), 'utf8')
    const crmPage = readFileSync(new URL('../../resources/js/pages/CrmPage.vue', import.meta.url), 'utf8')

    assert.doesNotMatch(pipeline, /store\.openLead\(/)
    assert.doesNotMatch(pipeline, /<button v-for=/)
    assert.match(pipeline, /<article v-for="lead in displayedLeads"/)
    assert.match(leadsPage, /@select="store\.openLead\(\$event\.id\)"/)
    assert.match(crmPage, /@select="selectLead"/)
})
